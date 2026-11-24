/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Email client (Email #email and Sales → Email #sales-email).

   2026-11-02, Anthony: "reply and reply to all in the mail client must have cc and bcc and preview with signature and
   ability to add attachments etc. any new mail that comes in alert me via the bell with a soft chime. add scroll bar to
   email body and email list etc - full sweep to make this look like outlook" and "i need ability to add other accounts
   such as info@kiddietrac.com and etc".

   OUTLOOK LAYOUT. Three panes on a desktop - mailboxes + folders │ message list │ reading pane - sized to the window
   so the page itself never scrolls; the list and the message each scroll on their own, with visible scroll bars.
   Phones show one pane at a time with a Back button.

   SEVERAL MAILBOXES. The left pane lists every connected mailbox (sales@, info@, ...) with its own folders and unread
   counts; "Add account" runs the same Sign in with Microsoft (or Gmail / IMAP) flow. A mailbox Microsoft stopped
   trusting shows a Reconnect banner (the server bells it once - App\Support\MicrosoftMailbox::authFailed).

   COMPOSE / REPLY / REPLY ALL / FORWARD (Sales → Email, Microsoft mailboxes): From picker (defaults to the mailbox the
   message came from), To / Cc / Bcc address chips (Cc and Bcc shown on demand), rich text with the mailbox's signature
   already in place (edit it here or in the mailbox settings), the quoted original underneath, a Preview of exactly what
   goes out, and attachments by picker or drag-and-drop (25 MB per email). Sent by App\Support\MailSend through
   Microsoft Graph. New mail rings the bell with a soft chime: kiddietrac:mail-watch writes the notification, and
   kt-sounds plays the "Email" tone; this screen only refreshes itself when it hears one.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Shell = KT.Shell;
  var Dom = KT.Dom || {};
  function toast(msg, kind) { try { if (Dom.toast) return Dom.toast(msg, kind || 'info'); if (KT.toast) return KT.toast('✉️', msg, '', '#0E7C90'); } catch (e) {} }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function api() { return KT.Api || window.Api; }
  /* Buttons opt out of kt-icon-buttons (it turns "New mail" / "Add account" into its add-pill and "Back" into a pill);
     the panes opt out of kt-button-fit (data-kt-btn-free) so toolbar buttons keep their 30px size. */
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; if (tag === 'button') e.setAttribute('data-kt-iconized', '1'); return e; }

  var MAX_TOTAL = 25 * 1024 * 1024, MAX_FILES = 10;
  var BASE = '/email';
  var STATE = fresh();
  function fresh() { return { accounts: [], acct: null, folder: 'INBOX', folders: {}, collapsed: {}, messages: [], open: null, detail: null, skip: 0, more: false, q: '', unread: false, seq: 0, sel: {}, anchor: null, autoPick: false, hl: null }; }
  function salesMode() { return BASE === '/sales/email'; }
  function acctById(id) { return STATE.accounts.filter(function (a) { return String(a.id) === String(id); })[0] || null; }
  function sendable() { return STATE.accounts.filter(function (a) { return a.can_send; }); }
  function ownAddresses() {
    var o = {};
    STATE.accounts.forEach(function (a) { [a.email_address, a.oauth_identity].forEach(function (x) { if (x) o[String(x).toLowerCase()] = 1; }); });
    return o;
  }
  function hashParam(k) { var m = (window.location.hash || '').match(new RegExp('[?&]' + k + '=([^&]+)')); return m ? decodeURIComponent(m[1]) : null; }
  function fmtBytes(n) { n = +n || 0; if (n < 1024) return n + ' B'; if (n < 1048576) return Math.round(n / 1024) + ' KB'; return (n / 1048576).toFixed(1) + ' MB'; }
  function initials(name) { return String(name || '?').replace(/<.*?>/g, '').trim().split(/[\s@._-]+/).filter(Boolean).map(function (p) { return p[0]; }).join('').slice(0, 2).toUpperCase() || '?'; }
  function avColor(s) {
    var palette = ['#0E7C90', '#7C3AED', '#DB2777', '#0284C7', '#059669', '#D97706', '#DC2626', '#4F46E5', '#0F766E', '#9333EA'];
    var h = 0; s = String(s || ''); for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return palette[h % palette.length];
  }
  function isPhone() { return window.matchMedia && window.matchMedia('(max-width:900px)').matches; }

  // ── Styles ──────────────────────────────────────────────────────────
  function styles() {
    if (document.getElementById('kt-email-style-v2')) return;
    var old = document.getElementById('kt-email-style'); if (old) old.remove();
    var s = document.createElement('style'); s.id = 'kt-email-style-v2';
    var light = '--ec-bg:#fff;--ec-pane:#F6F8FA;--ec-line:#E3E8EE;--ec-ink:#0F1E33;--ec-mute:#5B6B80;--ec-soft:#EEF3F7;--ec-hover:#F1F5F8;--ec-sel:#DCEFF3;--ec-acc:#0E7C90;--ec-acc-ink:#fff;--ec-thumb:#B9C5D1;--ec-warn-bg:#FFF4E5;--ec-warn-line:#F5C98B;--ec-warn-ink:#8A4B00;--ec-chip:#E8F1F5;';
    var dark = '--ec-bg:#0F1A2A;--ec-pane:#0B1522;--ec-line:#22324A;--ec-ink:#E2E8F0;--ec-mute:#94A3B8;--ec-soft:#16263A;--ec-hover:#132235;--ec-sel:#123B47;--ec-acc:#38BDCF;--ec-acc-ink:#04202A;--ec-thumb:#3A4D66;--ec-warn-bg:#3A2A12;--ec-warn-line:#7A5523;--ec-warn-ink:#F8D9A8;--ec-chip:#1B3045;';
    s.textContent = [
      '.ec-wrap{' + light + 'padding:10px 14px 0;width:100%;box-sizing:border-box;}',
      'html.kt-dark .ec-wrap,html[data-theme="dark"] .ec-wrap{' + dark + '}',
      '.ec-cmp{' + light + '}',
      '.ec-root{display:grid;grid-template-columns:236px 372px minmax(0,1fr);height:calc(100vh - 150px);min-height:420px;background:var(--ec-bg);border:1px solid var(--ec-line);border-radius:12px;overflow:hidden;color:var(--ec-ink);}',
      '@media(max-width:1250px){.ec-root{grid-template-columns:206px 312px minmax(0,1fr);}}',
      '@media(max-width:1100px){.ec-root{grid-template-columns:184px 284px minmax(0,1fr);}}',
      '.ec-folders,.ec-list{position:relative;}',
      '.ec-split{position:absolute;top:0;bottom:0;right:-5px;width:9px;z-index:6;cursor:col-resize;touch-action:none;background:transparent;}',
      '.ec-split::after{content:"";position:absolute;top:0;bottom:0;left:4px;width:1px;background:transparent;transition:background .12s,width .12s,left .12s;}',
      '.ec-split:hover::after,.ec-split:focus-visible::after,.ec-split.drag::after{left:3px;width:3px;background:var(--ec-acc);}',
      '.ec-split:focus-visible{outline:none;}',
      '.ec-root.ec-resizing,.ec-root.ec-resizing *{cursor:col-resize !important;user-select:none !important;}',
      '.ec-root.ec-resizing iframe{pointer-events:none;}',
      /* visible, slim scroll bars on every pane (Outlook shows them; overlay bars hid that a pane scrolls) */
      '.ec-scroll{overflow-y:auto;overflow-x:hidden;}',
      '@supports not selector(::-webkit-scrollbar){.ec-scroll{scrollbar-width:thin;scrollbar-color:var(--ec-thumb) transparent;}}',
      '@media(min-width:901px){.ec-root .ec-scroll{overscroll-behavior:contain;}}',
      '.ec-scroll::-webkit-scrollbar{width:10px;height:10px;}',
      '.ec-scroll::-webkit-scrollbar-thumb{background:var(--ec-thumb);border-radius:10px;border:2px solid transparent;background-clip:padding-box;}',
      '.ec-scroll::-webkit-scrollbar-track{background:transparent;}',
      /* left pane */
      '.ec-folders{background:var(--ec-pane);border-right:1px solid var(--ec-line);display:flex;flex-direction:column;min-height:0;}',
      '.ec-nav-top{padding:10px 10px 6px;}',
      '.ec-new{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;height:32px;border:none;border-radius:8px;background:var(--ec-acc);color:var(--ec-acc-ink);font:700 13px/1 inherit;font-family:inherit;cursor:pointer;}',
      '.ec-new:hover{filter:brightness(1.06);}',
      '.ec-tree{flex:1;min-height:0;padding:2px 6px 8px;}',
      '.ec-acct{margin-top:6px;}',
      /* Each mailbox its own light colour (2026-11-04, Anthony: "add a light colour to the email account header to help
         visually navigating from one to the next"): a tinted header with a coloured edge, and the faintest wash behind its
         folders. Translucent, so it reads on the dark theme too. */
      '.ec-acct[data-tint]{border-radius:9px;background:rgba(var(--ec-t),.05);padding-bottom:2px;}',
      '.ec-acct[data-tint]>div:first-child{background:rgba(var(--ec-t),.14);border-left:3px solid rgb(var(--ec-t));border-radius:8px;}',
      '.ec-acct[data-tint] .ec-acct-h:hover{background:rgba(var(--ec-t),.12);}',
      '.ec-acct[data-tint] .ec-acct-h .chev{color:rgb(var(--ec-t));}',
      '.ec-acct-h{display:flex;align-items:center;gap:6px;width:100%;border:none;background:transparent;color:var(--ec-ink);font:700 12.5px/1.2 inherit;font-family:inherit;padding:6px 6px;border-radius:7px;cursor:pointer;text-align:left;}',
      '.ec-acct-h:hover{background:var(--ec-hover);}',
      '.ec-acct-h .chev{width:12px;color:var(--ec-mute);font-size:10px;transition:transform .15s;}',
      '.ec-acct.closed .chev{transform:rotate(-90deg);}',
      '.ec-acct.closed .ec-flist{display:none;}',
      '.ec-acct-h .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
      '.ec-acct-h .dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}',
      '.ec-acct-gear{border:none;background:transparent;color:var(--ec-mute);cursor:pointer;font-size:13px;width:24px;height:24px;border-radius:6px;flex-shrink:0;}',
      '.ec-acct-gear:hover{background:var(--ec-soft);color:var(--ec-ink);}',
      '.ec-folder{display:flex;align-items:center;gap:8px;width:100%;border:none;background:transparent;border-radius:7px;padding:5px 8px 5px 24px;height:30px;box-sizing:border-box;cursor:pointer;font-size:13px;color:var(--ec-ink);text-align:left;font-family:inherit;}',
      '.ec-folder:hover{background:var(--ec-hover);}',
      '.ec-folder.on{background:var(--ec-sel);font-weight:700;}',
      '.ec-folder .ic{width:18px;text-align:center;flex-shrink:0;font-size:13px;}',
      '.ec-folder .lbl{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-folder .cnt{font-size:11.5px;font-weight:700;color:var(--ec-acc);}',
      '.ec-folder.on .cnt{color:var(--ec-ink);}',
      '.ec-nav-msg{font-size:11.5px;color:var(--ec-mute);padding:4px 8px 4px 24px;line-height:1.4;}',
      '.ec-nav-msg.err{color:#C2410C;}',
      '.ec-nav-foot{border-top:1px solid var(--ec-line);padding:6px;display:flex;flex-direction:column;gap:2px;}',
      '.ec-nav-link{display:flex;align-items:center;gap:8px;border:none;background:transparent;color:var(--ec-ink);font:600 12.5px/1 inherit;font-family:inherit;height:30px;padding:0 8px;border-radius:7px;cursor:pointer;text-align:left;}',
      '.ec-nav-link:hover{background:var(--ec-hover);}',
      /* message list */
      '.ec-list{border-right:1px solid var(--ec-line);display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--ec-bg);}',
      '.ec-list-head{padding:9px 12px 8px;border-bottom:1px solid var(--ec-line);display:flex;flex-direction:column;gap:7px;}',
      '.ec-lh-row{display:flex;align-items:center;gap:8px;min-width:0;}',
      '.ec-list-title{font-weight:800;font-size:15px;color:var(--ec-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-list-sub{font-size:11.5px;color:var(--ec-mute);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0;}',
      '.ec-lh-row.ec-lh-find{flex-wrap:wrap;row-gap:6px;}',
      '.ec-search{flex:1;min-width:150px;height:28px;box-sizing:border-box;border:1px solid var(--ec-line);border-radius:7px;padding:0 9px;font-size:12.5px;font-family:inherit;background:var(--ec-bg);color:var(--ec-ink);}',
      '.ec-seg{display:inline-flex;border:1px solid var(--ec-line);border-radius:7px;overflow:hidden;flex-shrink:0;}',
      '.ec-seg button{border:none;background:transparent;color:var(--ec-mute);font:600 12px/1 inherit;font-family:inherit;height:26px;padding:0 9px;cursor:pointer;}',
      '.ec-seg button.on{background:var(--ec-sel);color:var(--ec-ink);}',
      '.ec-ibtn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid var(--ec-line);border-radius:7px;background:var(--ec-bg);color:var(--ec-ink);cursor:pointer;font-size:13px;flex-shrink:0;padding:0;}',
      '.ec-ibtn:hover{background:var(--ec-hover);}',
      '.ec-list-scroll{flex:1;min-height:0;}',
      '.ec-item{position:relative;display:flex;gap:10px;width:100%;text-align:left;border:none;border-bottom:1px solid var(--ec-line);background:var(--ec-bg);padding:9px 12px 9px 14px;cursor:pointer;font-family:inherit;color:var(--ec-ink);}',
      '.ec-item:hover{background:var(--ec-hover);}',
      '.ec-item.on{background:var(--ec-sel);}',
      '.ec-item.unread::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--ec-acc);}',
      /* MULTI-SELECT (2026-11-03): Outlook's round check takes the avatar's place on hover, and on every row once
         anything is selected. Phones: long-press a row to start. */
      '.ec-item{-webkit-user-select:none;user-select:none;-webkit-touch-callout:none;}',
      '.ec-item.sel{background:var(--ec-sel);}',
      '.ec-avw{position:relative;flex-shrink:0;width:32px;height:32px;}',
      '.ec-ck{position:absolute;inset:0;display:none;align-items:center;justify-content:center;border-radius:50%;cursor:pointer;}',
      '.ec-ck::before{content:"";width:18px;height:18px;box-sizing:border-box;border:2px solid var(--ec-mute);border-radius:50%;background:var(--ec-bg);}',
      '.ec-ck[aria-checked="true"]::before{border-color:var(--ec-acc);background:var(--ec-acc);}',
      '.ec-ck[aria-checked="true"]::after{content:"";position:absolute;width:5px;height:9px;border:solid #fff;border-width:0 2px 2px 0;transform:translateY(-1px) rotate(45deg);}',
      '@media(hover:hover){.ec-item:hover .ec-ck{display:flex;}.ec-item:hover .ec-av{visibility:hidden;}}',
      '.ec-root.ec-selecting .ec-ck{display:flex;}',
      '.ec-root.ec-selecting .ec-item .ec-av{visibility:hidden;}',
      '.ec-ckall{position:relative;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;flex-shrink:0;border-radius:50%;cursor:pointer;}',
      '.ec-ckall::before{content:"";width:18px;height:18px;box-sizing:border-box;border:2px solid var(--ec-mute);border-radius:50%;background:var(--ec-bg);}',
      '.ec-ckall[aria-checked="true"]::before,.ec-ckall[aria-checked="mixed"]::before{border-color:var(--ec-acc);background:var(--ec-acc);}',
      '.ec-ckall[aria-checked="true"]::after{content:"";position:absolute;width:5px;height:9px;border:solid #fff;border-width:0 2px 2px 0;transform:translateY(-1px) rotate(45deg);}',
      '.ec-ckall[aria-checked="mixed"]::after{content:"";position:absolute;width:9px;height:2px;background:#fff;}',
      '.ec-ckall:focus-visible,.ec-ck:focus-visible{outline:2px solid var(--ec-acc);outline-offset:1px;}',
      '.ec-selbar{display:none;align-items:center;gap:2px;flex-wrap:wrap;padding:5px 8px;border-bottom:1px solid var(--ec-line);background:var(--ec-soft);z-index:4;}',
      '.ec-root.ec-selecting .ec-selbar{display:flex;}',
      '.ec-selbar .n{font-weight:800;font-size:12.5px;color:var(--ec-ink);margin:0 6px 0 2px;white-space:nowrap;}',
      '.ec-selbar .sp{flex:1;}',
      '.ec-list{container-type:inline-size;}',
      '@container (max-width:560px){.ec-sb .tl{display:none;}.ec-sb{padding:0 7px;}}',
      '.ec-sb{display:inline-flex;align-items:center;gap:4px;height:28px;padding:0 8px;border:1px solid transparent;border-radius:7px;background:transparent;color:var(--ec-ink);font:600 12px/1 inherit;font-family:inherit;cursor:pointer;white-space:nowrap;}',
      '.ec-sb:hover{background:var(--ec-hover);border-color:var(--ec-line);}',
      '.ec-sb.danger{color:#B91C1C;}',
      '.ec-menu{position:fixed;z-index:9600;min-width:200px;max-width:280px;max-height:60vh;overflow-y:auto;background:var(--ec-bg,#fff);border:1px solid var(--ec-line,#E2E8F0);border-radius:10px;box-shadow:0 12px 32px rgba(15,23,42,.18);padding:4px;}',
      '.ec-menu button{display:flex;align-items:center;gap:8px;width:100%;height:32px;border:none;background:transparent;border-radius:7px;padding:0 10px;font:600 13px/1 inherit;font-family:inherit;color:var(--ec-ink,#0F172A);cursor:pointer;text-align:left;}',
      '.ec-menu button:hover,.ec-menu button:focus-visible{background:var(--ec-hover,#F1F5F9);outline:none;}',
      '.ec-menu .hd{font-size:11px;font-weight:800;color:var(--ec-mute,#64748B);text-transform:uppercase;letter-spacing:.04em;padding:6px 10px 4px;}',
      '.ec-undo{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:9550;display:flex;align-items:center;gap:12px;max-width:calc(100vw - 32px);box-sizing:border-box;padding:10px 12px 10px 16px;border-radius:10px;background:#1E293B;color:#F8FAFC;font-size:13px;box-shadow:0 10px 30px rgba(15,23,42,.3);}',
      '.ec-undo .tx{min-width:0;}',
      '.ec-undo button{border:none;background:transparent;color:#7DD3FC;font:800 13px/1 inherit;font-family:inherit;cursor:pointer;padding:6px 8px;border-radius:6px;}',
      '.ec-undo button:hover{background:rgba(255,255,255,.08);}',
      '.ec-undo button.x{color:#CBD5E1;font-weight:600;}',
      '@media(max-width:900px){.ec-undo{bottom:96px;}.ec-sb .tl{display:none;}.ec-selbar{position:sticky;top:0;}.ec-ckall.h{display:none;}.ec-root.ec-selecting .ec-ckall.h{display:inline-flex;}}',
      '.ec-av{flex-shrink:0;width:32px;height:32px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;}',
      '.ec-it-main{display:block;flex:1;min-width:0;}',
      '.ec-it-top{display:flex;align-items:baseline;gap:6px;}',
      '.ec-from{flex:1;min-width:0;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-time{font-size:11.5px;color:var(--ec-mute);white-space:nowrap;flex-shrink:0;}',
      /* Mark read / unread on the row (2026-11-05): shown on hover with a mouse, always on touch screens. */
      '.ec-rd{flex-shrink:0;width:30px;height:26px;margin:-3px 0;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;font-size:16px;line-height:1;cursor:pointer;align-self:center;}',
      '.ec-rd:hover,.ec-rd:focus-visible{background:var(--ec-soft);outline:none;}',
      '@media(hover:hover){.ec-rd{visibility:hidden;font-size:14px;}.ec-item:hover .ec-rd,.ec-rd:focus-visible{visibility:visible;}}',
      '.ec-subj{display:flex;align-items:center;gap:5px;font-size:12.5px;margin-top:2px;}',
      '.ec-subj .t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-prev{display:block;font-size:12px;color:var(--ec-mute);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-item.unread .ec-from,.ec-item.unread .ec-subj .t{font-weight:800;}',
      '.ec-item.unread .ec-subj .t{color:var(--ec-acc);}',
      '.ec-flag{color:#DC2626;font-size:12px;}',
      '.ec-clip{font-size:12px;opacity:.8;}',
      '.ec-more{display:block;width:calc(100% - 24px);margin:10px 12px 14px;height:30px;border:1px dashed var(--ec-line);border-radius:8px;background:transparent;color:var(--ec-acc);font:700 12.5px/1 inherit;font-family:inherit;cursor:pointer;}',
      '.ec-empty{padding:28px 18px;color:var(--ec-mute);font-size:13px;text-align:center;line-height:1.5;}',
      '.ec-empty.err{color:#C2410C;}',
      /* reading pane */
      '.ec-read{display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--ec-bg);}',
      '.ec-tb{display:flex;align-items:center;gap:4px;padding:6px 10px;border-bottom:1px solid var(--ec-line);flex-wrap:wrap;background:var(--ec-bg);}',
      '.ec-tbtn{display:inline-flex;align-items:center;gap:5px;height:30px;padding:0 10px;border:1px solid transparent;border-radius:7px;background:transparent;color:var(--ec-ink);font:600 12.5px/1 inherit;font-family:inherit;cursor:pointer;white-space:nowrap;}',
      '.ec-tbtn:hover{background:var(--ec-hover);border-color:var(--ec-line);}',
      '.ec-tbtn[disabled]{opacity:.45;cursor:default;background:transparent;border-color:transparent;}',
      '.ec-tsep{width:1px;height:20px;background:var(--ec-line);margin:0 4px;}',
      '.ec-body{flex:1;min-height:0;}',
      '.ec-rh{padding:14px 20px 10px;}',
      '.ec-rsubj{font-size:18px;font-weight:800;color:var(--ec-ink);margin:0 0 12px;line-height:1.3;word-break:break-word;}',
      '.ec-rmeta{display:flex;gap:11px;align-items:flex-start;}',
      '.ec-rmeta .ec-av{width:38px;height:38px;font-size:14px;}',
      '.ec-rwho{flex:1;min-width:0;font-size:13px;line-height:1.5;}',
      '.ec-rwho .n{font-weight:700;}',
      '.ec-rwho .a{color:var(--ec-mute);}',
      '.ec-rwho .l{color:var(--ec-mute);font-size:12px;word-break:break-word;}',
      '.ec-fmt .ec-ai-b{color:#7C3AED;font-weight:700;}',
      '.ec-ai{border:1px solid #E4DDFB;background:#FAF8FF;border-radius:10px;padding:8px 10px;margin:6px 0;}',
      '.ec-ai-h{display:flex;align-items:center;gap:8px;font-weight:800;font-size:12.5px;color:#5B21B6;margin-bottom:6px;}',
      '.ec-ai-h span{flex:1;font-weight:500;color:var(--ec-mute);}',
      '.ec-ai-x{border:0;background:none;color:var(--ec-mute);cursor:pointer;font-size:14px;width:24px;height:24px;}',
      '.ec-ai-w{font-size:12.5px;color:var(--ec-mute);}',
      '.ec-ai-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;}',
      '.ec-ai-card{display:flex;flex-direction:column;gap:4px;text-align:left;border:1px solid #E4DDFB;background:#fff;border-radius:8px;padding:8px 10px;cursor:pointer;font-family:inherit;color:#1F2937;min-width:0;}',
      '.ec-ai-card:hover{border-color:#7C3AED;}',
      '.ec-ai-card b{font-size:12px;color:#5B21B6;}',
      '.ec-ai-card span{font-size:12.5px;line-height:1.45;display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden;white-space:pre-line;}',
      '.ec-ai-card i{font-style:normal;font-size:11.5px;font-weight:700;color:#7C3AED;}',
      '.ec-sub{display:inline-flex;align-items:center;gap:6px;margin-left:8px;vertical-align:middle;}',
      '.ec-sub-t{font-size:11.5px;color:var(--ec-mute);white-space:nowrap;}',
      '.ec-sub-t.on{color:#15803D;font-weight:700;}',
      '.ec-sub-b{height:24px;padding:0 10px;border:1px solid var(--ec-line);border-radius:999px;background:var(--ec-bg);color:var(--ec-acc);font:600 12px/22px inherit;font-family:inherit;cursor:pointer;white-space:nowrap;}',
      '.ec-sub-b:hover{border-color:var(--ec-acc);}',
      '.ec-sub-b:disabled{opacity:.6;cursor:default;}',
      '.ec-rwhen{font-size:12px;color:var(--ec-mute);white-space:nowrap;}',
      '.ec-atts{display:flex;flex-wrap:wrap;gap:7px;padding:4px 20px 12px;}',
      '.ec-att{display:inline-flex;align-items:center;gap:7px;max-width:250px;height:34px;box-sizing:border-box;padding:0 10px;border:1px solid var(--ec-line);border-radius:8px;background:var(--ec-soft);color:var(--ec-ink);font-size:12px;cursor:pointer;font-family:inherit;}',
      '.ec-att .nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-att .sz{color:var(--ec-mute);flex-shrink:0;}',
      '.ec-frame{display:block;width:100%;border:0;min-height:120px;background:#fff;}',
      '.ec-frame-wrap{margin:0 12px 16px;border:1px solid var(--ec-line);border-radius:10px;overflow:hidden;background:#fff;}',
      '.ec-read-empty{flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:var(--ec-mute);font-size:13.5px;text-align:center;padding:30px;}',
      '.ec-read-empty .big{font-size:42px;opacity:.6;}',
      '.ec-banner{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 8px;padding:8px 12px;border:1px solid var(--ec-warn-line);background:var(--ec-warn-bg);color:var(--ec-warn-ink);border-radius:9px;font-size:13px;}',
      '.ec-banner .tx{flex:1;min-width:200px;}',
      '.ec-pbtn{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border:none;border-radius:7px;background:var(--ec-acc);color:var(--ec-acc-ink);font:700 12.5px/1 inherit;font-family:inherit;cursor:pointer;white-space:nowrap;}',
      '.ec-sbtn{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border:1px solid var(--ec-line);border-radius:7px;background:var(--ec-bg);color:var(--ec-ink);font:600 12.5px/1 inherit;font-family:inherit;cursor:pointer;white-space:nowrap;}',
      '.ec-sbtn:hover{background:var(--ec-hover);}',
      '.ec-back{display:none;}',
      '@media(max-width:900px){',
      '.ec-wrap{padding:6px 8px 0;}',
      '.ec-root{grid-template-columns:minmax(0,1fr);height:auto !important;min-height:60vh;overflow:visible;}',
      '.ec-folders,.ec-list,.ec-read{min-height:60vh;}',
      '.ec-root .ec-tree,.ec-root .ec-list-scroll,.ec-root .ec-body{flex:none;height:auto;overflow:visible;overscroll-behavior:auto;}',
      '.ec-split{display:none !important;}',
      '.ec-folders,.ec-list,.ec-read{display:none;}',
      '.ec-root[data-mpane="folders"] .ec-folders{display:flex;}',
      '.ec-root[data-mpane="list"] .ec-list{display:flex;}',
      '.ec-root[data-mpane="read"] .ec-read{display:flex;}',
      '.ec-back{display:inline-flex;}',
      '.ec-tb{position:sticky;top:0;z-index:2;gap:6px 8px;}',
      '.ec-tbtn .tl{display:none;}',
      '.ec-rh{padding:12px 14px 8px;}',
      '.ec-rmeta{flex-wrap:wrap;row-gap:2px;}',
      '.ec-rwhen{order:3;flex-basis:100%;padding-left:49px;}',
      '.ec-rwho .a{display:block;overflow-wrap:anywhere;}',
      '.ec-frame-wrap{margin:0 6px 12px;}',
      '}',
      /* composer (inside the standard popup) */
      '.ec-cmp{display:flex;flex-direction:column;gap:0;color:var(--ec-ink);position:relative;}',
      '.ec-cmp.drag::after{content:"Drop files to attach";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(14,124,144,.10);border:2px dashed var(--ec-acc);border-radius:10px;font-weight:800;color:var(--ec-acc);font-size:15px;z-index:5;}',
      '.ec-frow{display:flex;align-items:flex-start;gap:8px;border-bottom:1px solid var(--ec-line);padding:4px 0;min-height:34px;}',
      '.ec-flbl{width:56px;flex-shrink:0;font-size:12.5px;font-weight:700;color:var(--ec-mute);padding-top:8px;}',
      '.ec-chips{flex:1;min-width:0;display:flex;flex-wrap:wrap;align-items:center;gap:4px;padding:3px 0;cursor:text;}',
      '.ec-chip{display:inline-flex;align-items:center;gap:4px;max-width:100%;height:24px;padding:0 4px 0 9px;border-radius:12px;background:var(--ec-chip);color:var(--ec-ink);font-size:12.5px;}',
      '.ec-chip .tx{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-chip.bad{background:#FDE2E2;color:#9F1239;}',
      '.ec-chip button{border:none;background:transparent;color:inherit;cursor:pointer;font-size:12px;width:18px;height:18px;border-radius:50%;padding:0;opacity:.7;}',
      '.ec-chip button:hover{opacity:1;background:rgba(0,0,0,.08);}',
      '.ec-chip-in{flex:1;min-width:140px;border:none !important;outline:none;background:transparent;font-size:13px;font-family:inherit;color:var(--ec-ink);height:26px;padding:0 2px !important;box-shadow:none !important;max-width:none !important;}',
      '.ec-ccbtns{display:flex;gap:2px;flex-shrink:0;padding-top:3px;}',
      '.ec-ccbtns button{border:none;background:transparent;color:var(--ec-acc);font:700 12.5px/1 inherit;font-family:inherit;cursor:pointer;height:26px;padding:0 7px;border-radius:6px;}',
      '.ec-ccbtns button:hover{background:var(--ec-hover);}',
      '.ec-plain{flex:1;min-width:0;border:none !important;outline:none;background:transparent;font-size:13.5px;font-family:inherit;color:var(--ec-ink);height:30px;padding:0 2px !important;box-shadow:none !important;max-width:none !important;}',
      '.ec-from-sel{flex:1;min-width:0;max-width:420px;height:30px;border:1px solid var(--ec-line);border-radius:7px;background:var(--ec-bg);color:var(--ec-ink);font-size:13px;font-family:inherit;padding:0 8px;}',
      '.ec-fmt{display:flex;align-items:center;gap:2px;flex-wrap:wrap;padding:6px 0;border-bottom:1px solid var(--ec-line);}',
      '.ec-fmt button{min-width:30px;height:28px;border:1px solid transparent;border-radius:6px;background:transparent;color:var(--ec-ink);font:600 13px/1 inherit;font-family:inherit;cursor:pointer;padding:0 6px;}',
      '.ec-fmt button:hover{background:var(--ec-hover);border-color:var(--ec-line);}',
      '.ec-fmt button.on{background:var(--ec-sel);}',
      '.ec-fmt .sp{flex:1;}',
      '.ec-editor{min-height:min(200px,24vh);max-height:46vh;padding:12px 4px;outline:none;font-size:14px;line-height:1.55;color:var(--ec-ink);word-wrap:break-word;}',
      '.ec-editor:empty::before{content:attr(data-ph);color:var(--ec-mute);}',
      '.ec-editor .ec-sig{margin-top:14px;padding-top:2px;color:var(--ec-ink);}',
      '.ec-editor blockquote{margin:0 0 0 8px;padding-left:10px;border-left:3px solid var(--ec-line);}',
      '.ec-preview{border:1px solid var(--ec-line);border-radius:10px;overflow:hidden;margin:10px 0;background:#fff;}',
      '.ec-preview iframe{display:block;width:100%;height:46vh;border:0;background:#fff;}',
      '.ec-quote{margin:8px 0 4px;border:1px solid var(--ec-line);border-radius:10px;overflow:hidden;}',
      '.ec-quote-h{display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--ec-soft);font-size:12px;color:var(--ec-mute);}',
      '.ec-quote-h .tx{flex:1;min-width:0;}',
      '.ec-quote iframe{display:block;width:100%;height:min(220px,26vh);border:0;background:#fff;}',
      '.ec-quote.closed iframe{display:none;}',
      '.ec-files{display:flex;flex-wrap:wrap;gap:6px;padding:8px 0 2px;}',
      '.ec-file{display:inline-flex;align-items:center;gap:6px;max-width:260px;height:30px;padding:0 6px 0 10px;border:1px solid var(--ec-line);border-radius:8px;background:var(--ec-soft);font-size:12px;color:var(--ec-ink);}',
      '.ec-file .nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-file .sz{color:var(--ec-mute);flex-shrink:0;}',
      '.ec-file button{border:none;background:transparent;color:var(--ec-mute);cursor:pointer;width:20px;height:20px;border-radius:50%;padding:0;}',
      '.ec-file button:hover{background:rgba(0,0,0,.08);color:var(--ec-ink);}',
      '.ec-file.orig{border-style:dashed;}',
      '.ec-cfoot{display:flex;align-items:center;gap:10px;padding:10px 0 4px;border-top:1px solid var(--ec-line);margin-top:8px;position:sticky;bottom:0;z-index:3;background:var(--ec-bg);box-shadow:0 16px 0 0 var(--ec-bg);}',
      '@media(max-width:900px){',
      '.ec-cmp .ec-editor{max-height:none;overflow:visible;min-height:140px;}',
      '.ec-cfoot{gap:8px;padding:8px 0 2px;}',
      '.ec-cfoot .st .sgtx,.ec-cfoot .lim{display:none;}',
      '.ec-cmp .ec-chip-in{min-width:90px;}',
      '}',
      ':is(#appMain,.kt-modal,[role="dialog"],body) .ec-cmp .ec-chips input.ec-chip-in,:is(#appMain,.kt-modal,[role="dialog"],body) .ec-cmp .ec-frow input.ec-plain{max-width:none !important;border:none !important;box-shadow:none !important;background:transparent !important;outline:none !important;border-radius:0 !important;width:auto !important;}',
      '.ec-cmp input.ec-chip-in::-webkit-calendar-picker-indicator{display:none !important;opacity:0 !important;}',
      '.ec-cfoot .st{flex:1;min-width:0;font-size:12.5px;color:#B91C1C;}',
      '.ec-cfoot .st.ok{color:var(--ec-mute);}',
      '.ec-cfoot .lim{font-size:11.5px;color:var(--ec-mute);white-space:nowrap;}',
      '.ec-sig-ed{min-height:110px;max-height:260px;border:1px solid var(--ec-line);border-radius:9px;padding:10px 12px;outline:none;font-size:13.5px;line-height:1.5;background:var(--ec-bg);color:var(--ec-ink);}',
      '.ec-sig-ed:focus{border-color:var(--ec-acc);}'
    ].join('\n');
    document.head.appendChild(s);
  }

  // ── Data ────────────────────────────────────────────────────────────
  function loadAccounts() {
    return api().get(BASE + '/accounts').then(function (r) { STATE.accounts = (r && r.accounts) || []; return STATE.accounts; });
  }
  function folderOrder(f) {
    var n = String(f.label || '').toLowerCase();
    var order = ['inbox', 'drafts', 'sent items', 'sent', 'deleted items', 'trash', 'junk email', 'junk', 'spam', 'archive'];
    var i = order.indexOf(n); return i === -1 ? 50 : i;
  }
  var HIDE_EMPTY = /^(outbox|conversation history|sync issues|rss feeds|rss subscriptions)$/i;
  function loadFolders(a) {
    /* A mailbox that has to sign in (again) has no folders to show: its banner says so and offers the sign-in, as
       the message list already did (loadList). Asking anyway was refused every time the screen opened and filled the
       audit log with "Couldn't load sales email folders" (2026-11-04: a Microsoft sign-in started, never finished). */
    if (a.needs_reconnect) {
      STATE.folders[a.id] = { list: [], error: 'Sign in with ' + (a.provider === 'google' ? 'Google' : 'Microsoft') + ' to see its folders.' };
      return Promise.resolve([]);
    }
    return api().get(BASE + '/folders?account_id=' + a.id).then(function (r) {
      var list = ((r && r.folders) || []).filter(function (f) { return !(HIDE_EMPTY.test(f.label) && !f.total); });
      list.sort(function (x, y) { return folderOrder(x) - folderOrder(y) || String(x.label).localeCompare(String(y.label)); });
      STATE.folders[a.id] = { list: list };
      return list;
    }).catch(function (e) { STATE.folders[a.id] = { list: [], error: (e && e.message) || 'Could not load folders' }; return []; });
  }
  function folderInfo(a, key) {
    var f = (STATE.folders[a && a.id] || {}).list || [];
    return f.filter(function (x) { return x.key === key; })[0] || null;
  }
  function msgPath(a, uid, suffix) {
    var n = /^\d+$/.test(String(uid));
    return BASE + '/messages/' + (n ? uid : 'm') + (suffix || '') + '?account_id=' + a.id + (n ? '' : '&mid=' + encodeURIComponent(uid));
  }
  function isSentLike(a, key) {
    var f = folderInfo(a, key); var n = String((f && f.label) || key).toLowerCase();
    return /sent|draft|outbox/.test(n);
  }

  // ── Screen ──────────────────────────────────────────────────────────
  var R = null;   // the live screen's elements
  function render(main) {
    var want = /^#?sales-email/.test(window.location.hash || '') ? '/sales/email' : '/email';
    if (want !== BASE) { BASE = want; STATE = fresh(); }
    styles();
    main.innerHTML = '';
    var wrap = el('div', 'ec-wrap'); wrap.setAttribute('data-kt-btn-free', ''); wrap.setAttribute('data-kt-nospin', '');
    /* The screen shows its own changes (rows leave, flags and counts update at once): kt-live must not tear it down
       and rebuild it after every write - that wiped the selection, the open message and the Undo. */
    wrap.setAttribute('data-kt-self-live', '');
    var banners = el('div'); wrap.appendChild(banners);
    var root = el('div', 'ec-root'); root.setAttribute('data-mpane', 'list');
    /* THE CLIENT SPACES ITSELF (2026-11-05, Anthony: "email client on phone when opened shrinks and resizes"). On a phone
       kt-button-breathe.js measured the screen ~0.4s after every change and pushed "crowded" buttons apart: each
       message-list row (a full-width button) got 14px on top, and the reading toolbar 4px more between icons, so it
       re-wrapped and the open email jumped down. The client's own phone CSS sets that spacing from the first paint. */
    root.setAttribute('data-kt-nobreathe', '1');
    root.innerHTML =
      '<aside class="ec-folders"><div class="ec-split" data-split="1" role="separator" aria-orientation="vertical" aria-label="Resize the folder pane" title="Drag to resize · double-click to reset" tabindex="0"></div><div class="ec-nav-top"><button class="ec-new" type="button" data-kt-iconized="1">✏️ New mail</button></div>' +
        '<div class="ec-tree ec-scroll" data-kt-scroll></div>' +
        '<div class="ec-nav-foot"><button class="ec-nav-link" data-x="add" type="button" data-kt-iconized="1">＋ Add account</button><button class="ec-nav-link" data-x="manage" type="button" data-kt-iconized="1">⚙️ Manage accounts</button></div></aside>' +
      '<div class="ec-list"><div class="ec-list-head">' +
        '<div class="ec-lh-row"><button class="ec-ibtn ec-back" data-to="folders" type="button" title="Folders" data-kt-iconized="1">☰</button><span class="ec-ckall h" role="checkbox" aria-checked="false" tabindex="0" aria-label="Select all messages" title="Select all"></span><span class="ec-list-title">Inbox</span><span class="ec-list-sub"></span>' +
        '<button class="ec-ibtn" data-x="refresh" type="button" title="Refresh" data-kt-iconized="1">⟳</button></div>' +
        '<div class="ec-lh-row ec-lh-find"><input class="ec-search" type="search" placeholder="Search this folder" data-kt-nospell><span class="ec-seg" role="group" aria-label="Show"><button type="button" data-f="all" class="on" data-kt-iconized="1">All</button><button type="button" data-f="unread" data-kt-iconized="1">Unread</button></span></div>' +
      '</div><div class="ec-selbar" role="toolbar" aria-label="Selected messages"></div><div class="ec-list-scroll ec-scroll" data-kt-scroll></div><div class="ec-split" data-split="2" role="separator" aria-orientation="vertical" aria-label="Resize the message list" title="Drag to resize · double-click to reset" tabindex="0"></div></div>' +
      '<section class="ec-read"></section>';
    wrap.appendChild(root);
    main.appendChild(wrap);
    R = { wrap: wrap, root: root, banners: banners, tree: root.querySelector('.ec-tree'), list: root.querySelector('.ec-list-scroll'),
          title: root.querySelector('.ec-list-title'), sub: root.querySelector('.ec-list-sub'), read: root.querySelector('.ec-read'), search: root.querySelector('.ec-search'),
          selbar: root.querySelector('.ec-selbar'), ckall: root.querySelector('.ec-ckall') };
    R.ckall.addEventListener('click', function () { selectAll(); });
    R.ckall.addEventListener('keydown', function (e) { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); selectAll(); } });
    bindKeys();
    emptyReading();
    initSplits(); applyWidths();

    root.querySelector('.ec-new').addEventListener('click', function () { compose('new', null); });
    root.querySelector('[data-x="add"]').addEventListener('click', function () { openWizard(); });
    root.querySelector('[data-x="manage"]').addEventListener('click', function () { openAccounts(); });
    root.querySelector('[data-x="refresh"]').addEventListener('click', function () { refreshAll(true); });
    root.querySelector('[data-to="folders"]').addEventListener('click', function () { setPane('folders'); });
    [].forEach.call(root.querySelectorAll('.ec-seg button'), function (b) {
      b.addEventListener('click', function () {
        [].forEach.call(root.querySelectorAll('.ec-seg button'), function (x) { x.classList.toggle('on', x === b); });
        STATE.unread = b.getAttribute('data-f') === 'unread'; loadList(false);
      });
    });
    var st = null;
    R.search.addEventListener('input', function () { clearTimeout(st); st = setTimeout(function () { STATE.q = R.search.value.trim(); loadList(false); }, 380); });
    sizeToWindow();

    // Back from Microsoft sign-in? Finish it, as this signed-in user, before loading.
    var msRet = takeMicrosoftReturn();
    var ready = Promise.resolve();
    if (msRet && msRet.error) { setTimeout(function () { showMicrosoftProblem(msRet.error); }, 300); }
    else if (msRet && msRet.nonce) {
      ready = api().post(BASE + '/microsoft/finish', { nonce: msRet.nonce })
        .then(function (r) { toast('Mailbox connected with ' + (msRet.nonce.charAt(0) === 'g' ? 'Google' : 'Microsoft') + ' ✓', 'success'); if (r && r.account) STATE.acct = r.account; })
        .catch(function (e) { setTimeout(function () { showMicrosoftProblem((e && e.message) || 'The sign-in could not be finished.'); }, 300); });
    }
    R.tree.innerHTML = '<div class="ec-nav-msg">Loading mailboxes…</div>';
    R.list.innerHTML = '<div class="ec-empty">Loading…</div>';
    ready.then(loadAccounts).then(function (accts) {
      drawBanners();
      if (!accts.length) { noAccounts(); return; }
      var want = hashParam('acct');
      var keep = STATE.acct && acctById(STATE.acct.id);
      STATE.acct = (want && acctById(want)) || keep || accts.filter(function (a) { return a.is_default && !a.needs_reconnect; })[0]
        || accts.filter(function (a) { return a.status === 'connected'; })[0] || accts[0];
      if (want) STATE.folder = 'INBOX';
      drawTree();
      Promise.all(accts.map(function (a) { return loadFolders(a); })).then(drawTree);
      STATE.autoPick = true; STATE.hl = null;
      loadList(false);
      setPane(isPhone() ? 'list' : 'list');
    }).catch(function (e) { R.tree.innerHTML = '<div class="ec-nav-msg err">' + esc((e && e.message) || 'Could not load mailboxes') + '</div>'; R.list.innerHTML = ''; });
    startPolling();
  }

  function setPane(p) { if (R && R.root) R.root.setAttribute('data-mpane', p); }

  /* Fill the window: the three panes scroll, the page does not. Phones flow as a normal page (kt-mobile-app.css). */
  function fitRoot() {
    if (!R || !R.root.isConnected) return;
    applyWidths();
    if (window.innerWidth <= 900) { R.root.style.height = ''; return; }
    var top = R.root.getBoundingClientRect().top + (window.scrollY || 0);
    R.root.style.height = Math.max(420, window.innerHeight - top - 12) + 'px';
  }
  /* PANE WIDTHS (Outlook): drag the line between folders|list and list|reading; widths are remembered for this
     viewer in this browser (localStorage, per-viewer convenience only); double-click a line to go back to the default. */
  var PANE_KEY = 'kt_ec_panes_v1', MIN1 = 150, MAX1 = 420, MIN2 = 240, MAX2 = 720, MIN_READ = 340;
  function savedWidths() { try { var v = JSON.parse(localStorage.getItem(PANE_KEY) || 'null'); return v && v.w1 && v.w2 ? v : null; } catch (e) { return null; } }
  function storeWidths(v) { try { if (v) localStorage.setItem(PANE_KEY, JSON.stringify(v)); else localStorage.removeItem(PANE_KEY); } catch (e) {} }
  function clampWidths(w1, w2) {
    var total = R.root.clientWidth;
    w1 = Math.max(MIN1, Math.min(MAX1, Math.round(w1)));
    w2 = Math.max(MIN2, Math.min(MAX2, Math.round(w2)));
    var over = w1 + w2 + MIN_READ - total;
    if (over > 0) { var take = Math.min(over, w2 - MIN2); w2 -= take; over -= take; if (over > 0) w1 = Math.max(MIN1, w1 - over); }
    return { w1: w1, w2: w2 };
  }
  function applyWidths(v) {
    if (!R || !R.root) return;
    if (window.innerWidth <= 900) { R.root.style.gridTemplateColumns = ''; return; }
    v = v || savedWidths();
    if (!v) { R.root.style.gridTemplateColumns = ''; return; }
    var c = clampWidths(v.w1, v.w2);
    R.root.style.gridTemplateColumns = c.w1 + 'px ' + c.w2 + 'px minmax(0,1fr)';
  }
  function currentWidths() {
    return { w1: R.root.querySelector('.ec-folders').getBoundingClientRect().width, w2: R.root.querySelector('.ec-list').getBoundingClientRect().width };
  }
  function initSplits() {
    [].forEach.call(R.root.querySelectorAll('.ec-split'), function (h) {
      var which = h.getAttribute('data-split');
      h.addEventListener('pointerdown', function (e) {
        if (e.button !== 0 || window.innerWidth <= 900) return;
        e.preventDefault();
        var start = currentWidths(), x0 = e.clientX, last = start;
        try { h.setPointerCapture(e.pointerId); } catch (x) {}
        h.classList.add('drag'); R.root.classList.add('ec-resizing');
        function move(ev) {
          var dx = ev.clientX - x0;
          last = clampWidths(which === '1' ? start.w1 + dx : start.w1, which === '2' ? start.w2 + dx : start.w2);
          R.root.style.gridTemplateColumns = last.w1 + 'px ' + last.w2 + 'px minmax(0,1fr)';
        }
        function up() {
          h.removeEventListener('pointermove', move); h.removeEventListener('pointerup', up); h.removeEventListener('pointercancel', up);
          h.classList.remove('drag'); R.root.classList.remove('ec-resizing');
          storeWidths(last);
        }
        h.addEventListener('pointermove', move); h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
      });
      h.addEventListener('dblclick', function () { storeWidths(null); applyWidths(); toast('Pane widths reset.', 'info'); });
      h.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        var c = currentWidths(), d = e.key === 'ArrowLeft' ? -16 : 16;
        var v = clampWidths(which === '1' ? c.w1 + d : c.w1, which === '2' ? c.w2 + d : c.w2);
        applyWidths(v); storeWidths(v);
      });
    });
  }
  var fitBound = false;
  function sizeToWindow() {
    if (!fitBound) { fitBound = true; window.addEventListener('resize', fitRoot); }
    fitRoot(); setTimeout(fitRoot, 120); setTimeout(fitRoot, 600);
  }

  function noAccounts() {
    R.tree.innerHTML = '<div class="ec-nav-msg">No mailbox connected yet.</div>';
    R.title.textContent = 'Email'; R.sub.textContent = '';
    R.list.innerHTML = '';
    var box = el('div', 'ec-empty');
    box.innerHTML = '<div style="font-size:38px;margin-bottom:6px;">📭</div><div style="font-weight:700;color:var(--ec-ink);margin-bottom:4px;">Connect a mailbox</div>'
      + (salesMode() ? 'Add sales@, info@ or any other KiddieTrac mailbox. Microsoft 365 mailboxes sign in on Microsoft’s own page.' : 'Add your Microsoft 365, Gmail or other mailbox to read it here.');
    var b = el('button', 'ec-pbtn', '＋ Add account'); b.type = 'button'; b.style.marginTop = '12px';
    b.addEventListener('click', function () { openWizard(); });
    box.appendChild(el('div')).appendChild(b);
    R.list.appendChild(box);
  }

  function drawBanners() {
    if (!R) return;
    wizardCss();
    R.banners.innerHTML = '';
    STATE.accounts.forEach(function (a) {
      if (!a.needs_reconnect) return;
      var bn = el('div', 'ec-banner');
      var who = a.provider === 'google' ? 'Google' : 'Microsoft';
      var never = (a.provider === 'microsoft' || a.provider === 'google') && a.auth_type !== a.provider;
      bn.innerHTML = '<span>⚠️</span><span class="tx"><strong>' + esc(a.email_address) + '</strong> ' + (never
        ? 'is not signed in with ' + who + ' yet, so it cannot be read here.'
        : 'needs you to sign in again. ' + who + ' stopped accepting the saved sign-in (a password change, a withdrawn permission' + (a.provider === 'google' ? ', or Google\'s 7-day limit' : '') + ').') + '</span>';
      var b = el('button', 'ec-pbtn', never ? logo(a.provider === 'google' ? 'google' : 'microsoft', 15) + (a.provider === 'google' ? ' Sign in with Google' : ' Sign in with Microsoft') : '🔌 Reconnect'); b.type = 'button';
      b.addEventListener('click', function () { startMicrosoft(a.id, b, null, a.provider); });
      bn.appendChild(b);
      R.banners.appendChild(bn);
    });
    sizeToWindow();
  }

  // ── Left pane: mailboxes + folders ─────────────────────────────────
  var ICON = { inbox: '📥', drafts: '📝', 'sent items': '📤', sent: '📤', 'deleted items': '🗑️', trash: '🗑️', 'junk email': '🚫', junk: '🚫', spam: '🚫', archive: '🗂️' };
  function drawTree() {
    if (!R) return;
    R.tree.innerHTML = '';
    STATE.accounts.forEach(function (a) {
      var box = el('div', 'ec-acct' + (STATE.collapsed[a.id] ? ' closed' : ''));
      var TINTS = ['59,130,246', '16,185,129', '245,158,11', '139,92,246', '244,63,94', '20,184,166'];
      box.setAttribute('data-tint', '1'); box.style.setProperty('--ec-t', TINTS[STATE.accounts.indexOf(a) % TINTS.length]);
      var fi = STATE.folders[a.id];
      var inbox = fi && fi.list ? fi.list.filter(function (f) { return f.key === 'INBOX'; })[0] : null;
      var dot = a.needs_reconnect || a.status === 'error' ? '#F59E0B' : (a.status === 'connected' ? '#10B981' : '#94A3B8');
      var h = el('div'); h.style.cssText = 'display:flex;align-items:center;gap:2px;';
      var hb = el('button', 'ec-acct-h'); hb.type = 'button'; hb.title = a.email_address;
      hb.innerHTML = '<span class="chev">▼</span><span class="dot" style="background:' + dot + '"></span><span class="nm">' + esc(a.display_name || a.email_address) + '</span>'
        // New-mail alerts switched off for me on this mailbox (its settings, 2026-11-05).
        + (a.alerts && a.alerts.supported && !a.alerts.on ? '<span data-kttip="New-mail alerts are off for this mailbox" style="font-size:11px;opacity:.75;flex-shrink:0;">🔕</span>' : '')
        + (STATE.collapsed[a.id] && inbox && inbox.unread ? '<span class="cnt" style="font-size:11.5px;color:var(--ec-acc);font-weight:700;">' + inbox.unread + '</span>' : '');
      hb.addEventListener('click', function () { STATE.collapsed[a.id] = !STATE.collapsed[a.id]; drawTree(); });
      var gear = el('button', 'ec-acct-gear', '⚙'); gear.type = 'button'; gear.title = 'Settings for ' + a.email_address; gear.setAttribute('data-kt-iconized', '1');
      gear.addEventListener('click', function (e) { e.stopPropagation(); openAccountSettings(a); });
      h.appendChild(hb); h.appendChild(gear); box.appendChild(h);
      var fl = el('div', 'ec-flist');
      if (a.display_name) { var sub = el('div', 'ec-nav-msg'); sub.style.paddingTop = '0'; sub.textContent = a.email_address; fl.appendChild(sub); }
      if (a.needs_reconnect) {
        var rc = el('div', 'ec-nav-msg err', 'Needs to sign in again. ');
        var rb = el('button', 'ec-sbtn', '🔌 Reconnect'); rb.type = 'button'; rb.style.cssText += 'height:26px;margin-top:4px;';
        rb.addEventListener('click', function () { startMicrosoft(a.id, rb, null, a.provider); });
        rc.appendChild(rb); fl.appendChild(rc);
      } else if (!fi) {
        fl.appendChild(el('div', 'ec-nav-msg', 'Loading folders…'));
      } else if (fi.error) {
        fl.appendChild(el('div', 'ec-nav-msg err', esc(fi.error)));
      } else {
        fi.list.forEach(function (f) {
          var on = STATE.acct && STATE.acct.id === a.id && STATE.folder === f.key;
          var b = el('button', 'ec-folder' + (on ? ' on' : '')); b.type = 'button';
          var ic = ICON[String(f.label).toLowerCase()] || f.special || '📁';
          var cnt = /^(sent|sent items|drafts)$/i.test(f.label) ? (/drafts/i.test(f.label) && f.total ? f.total : 0) : f.unread;
          b.innerHTML = '<span class="ic">' + ic + '</span><span class="lbl">' + esc(f.label) + '</span>' + (cnt ? '<span class="cnt">' + cnt + '</span>' : '');
          b.addEventListener('click', function () {
            STATE.acct = a; STATE.folder = f.key; STATE.open = null; STATE.q = ''; R.search.value = '';
            STATE.autoPick = true; STATE.hl = null;
            drawTree(); loadList(false); emptyReading(); setPane('list');
          });
          fl.appendChild(b);
        });
      }
      box.appendChild(fl);
      R.tree.appendChild(box);
    });
  }

  // ── Message list ────────────────────────────────────────────────────
  function loadList(append, quiet) {
    if (!R || !STATE.acct) return;
    var a = STATE.acct;
    var f = folderInfo(a, STATE.folder);
    R.title.textContent = f ? f.label : (STATE.folder === 'INBOX' ? 'Inbox' : 'Folder');
    R.sub.textContent = a.email_address;
    if (a.needs_reconnect) {
      R.list.innerHTML = '<div class="ec-empty">' + esc(a.email_address) + ' needs to sign in with ' + (a.provider === 'google' ? 'Google' : 'Microsoft') + ' again before its mail can be shown.</div>';
      return;
    }
    if (!append) STATE.skip = 0;
    if (!append && !quiet) clearSel(true);
    var seq = ++STATE.seq;
    if (!append && !quiet) R.list.innerHTML = '<div class="ec-empty">Loading…</div>';
    var url = BASE + '/messages?account_id=' + a.id + '&folder=' + encodeURIComponent(STATE.folder) + '&limit=40&skip=' + STATE.skip
      + (STATE.q ? '&q=' + encodeURIComponent(STATE.q) : '') + (STATE.unread ? '&unread=1' : '');
    api().get(url).then(function (r) {
      if (seq !== STATE.seq || !R) return;
      var msgs = ((r && r.messages) || []).map(function (m) { m.id = m.uid; return m; });
      if (STATE.unread) msgs = msgs.filter(function (m) { return m.unread; });
      STATE.messages = append ? STATE.messages.concat(msgs) : msgs;
      var live = {}; STATE.messages.forEach(function (m) { live[m.uid] = 1; });
      Object.keys(STATE.sel).forEach(function (k) { if (!live[k]) delete STATE.sel[k]; });
      STATE.more = !!(r && r.more) && !STATE.q;
      drawList(append);
      if (!append && STATE.autoPick) { STATE.autoPick = false; pickNewest(); }
    }).catch(function (e) {
      if (seq !== STATE.seq || !R) return;
      STATE.autoPick = false;
      if (quiet) return;
      R.list.innerHTML = '<div class="ec-empty err">' + esc((e && e.message) || 'Could not load messages') + '</div>';
      if (e && /sign in|Reconnect|expired|withdrawn/i.test(e.message || '')) loadAccounts().then(function () { drawBanners(); drawTree(); });
    });
  }
  function drawList() {
    var top = R.list.scrollTop;
    R.list.innerHTML = '';
    var a = STATE.acct, sent = isSentLike(a, STATE.folder);
    if (!STATE.messages.length) {
      R.list.innerHTML = '<div class="ec-empty">' + (STATE.q ? 'Nothing matches “' + esc(STATE.q) + '”.' : (STATE.unread ? 'No unread messages.' : 'Nothing in this folder.')) + '</div>';
      return;
    }
    var frag = document.createDocumentFragment();
    STATE.messages.forEach(function (m) {
      var who = sent ? ('To: ' + (m.to || '(no recipient)')) : (m.from || m.email || '(unknown)');
      var it = el('button', 'ec-item' + (m.unread ? ' unread' : '') + ((STATE.open ? STATE.open.uid === m.uid : STATE.hl === m.uid) ? ' on' : '') + (STATE.sel[m.uid] ? ' sel' : '')); it.type = 'button';
      it.setAttribute('data-uid', m.uid);
      var avName = sent ? (m.to || '?') : who;
      it.innerHTML = '<span class="ec-avw"><span class="ec-av" data-kt-emoji="off" style="background:' + avColor(avName) + '">' + esc(initials(avName)) + '</span>'
        + '<span class="ec-ck" role="checkbox" aria-checked="' + (STATE.sel[m.uid] ? 'true' : 'false') + '" aria-label="Select this message"></span></span>'
        + '<span class="ec-it-main"><span class="ec-it-top"><span class="ec-from">' + (m.draft ? '<span style="color:#DC2626;font-weight:700;">[Draft]</span> ' : '') + esc(who) + '</span>'
          + (m.draft ? '' : '<span class="ec-rd" role="button" tabindex="0" aria-label="' + (m.unread ? 'Mark read' : 'Mark unread') + '" data-kttip="' + (m.unread ? 'Mark read' : 'Mark unread') + '">' + (m.unread ? '📭' : '✉️') + '</span>')
          + '<span class="ec-time">' + esc(m.date) + '</span></span>'
        + '<span class="ec-subj"><span class="t">' + esc(m.subject) + '</span>' + (m.hasAttach ? '<span class="ec-clip" title="Has attachments">📎</span>' : '') + (m.starred ? '<span class="ec-flag" title="Flagged">⚑</span>' : '') + '</span>'
        + (m.preview ? '<span class="ec-prev">' + esc(m.preview) + '</span>' : '') + '</span>';
      wireItem(it, m);
      frag.appendChild(it);
    });
    R.list.appendChild(frag);
    if (STATE.more) {
      var more = el('button', 'ec-more', 'Load older messages'); more.type = 'button';
      more.addEventListener('click', function () { more.disabled = true; more.textContent = 'Loading…'; STATE.skip += 40; loadList(true); });
      R.list.appendChild(more);
    }
    R.list.scrollTop = top;
    drawSelBar();
  }
  /* NEWEST MESSAGE SELECTED BY DEFAULT (2026-11-03, Anthony: "By default show the newest email selected"). When the
     screen opens or another folder / mailbox is picked: on a computer the newest message opens in the reading pane;
     on a phone (one column) the list stays and the newest row is only highlighted - opening it would hide the list.
     Opened this way it is NOT marked read (peek): it turns read when it is actually picked, as before. */
  function pickNewest() {
    var m = STATE.messages[0];
    if (!R || !m || STATE.open || selCount()) return;
    STATE.anchor = m.uid;
    if (isPhone()) {
      STATE.hl = m.uid;
      var it = R.list.querySelector('.ec-item[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(String(m.uid)) : String(m.uid)) + '"]');
      if (it) it.classList.add('on');
      return;
    }
    openMessage(m, true);
  }
  function markListItem(m) {
    if (!R) return;
    var it = R.list.querySelector('.ec-item[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(String(m.uid)) : String(m.uid)) + '"]');
    [].forEach.call(R.list.querySelectorAll('.ec-item.on'), function (x) { x.classList.remove('on'); });
    if (it) { it.classList.add('on'); it.classList.toggle('unread', !!m.unread); var rb = it.querySelector('.ec-rd'); if (rb) { rb.textContent = m.unread ? '📭' : '✉️'; rb.setAttribute('aria-label', m.unread ? 'Mark read' : 'Mark unread'); rb.setAttribute('data-kttip', m.unread ? 'Mark read' : 'Mark unread'); } var fl = it.querySelector('.ec-flag'); if (m.starred && !fl) it.querySelector('.ec-subj').insertAdjacentHTML('beforeend', '<span class="ec-flag" title="Flagged">⚑</span>'); if (!m.starred && fl) fl.remove(); }
  }
  function bumpUnread(a, key, d) {
    var f = folderInfo(a, key); if (!f) return;
    f.unread = Math.max(0, (f.unread || 0) + d); drawTree();
  }

  // ── Reading pane ────────────────────────────────────────────────────
  function emptyReading() {
    if (!R) return;
    R.read.innerHTML = '<div class="ec-read-empty"><div class="big">✉️</div><div>Select a message to read it here.</div></div>';
    STATE.open = null; STATE.detail = null;
  }
  function toolbar(m, d) {
    var a = STATE.acct;
    var tb = el('div', 'ec-tb');
    var back = el('button', 'ec-tbtn ec-back', '‹ <span class="tl">Back</span>'); back.type = 'button'; back.title = 'Back to the list';
    back.addEventListener('click', function () { setPane('list'); });
    tb.appendChild(back);
    function btn(icon, label, fn, dis) {
      var b = el('button', 'ec-tbtn', '<span>' + icon + '</span><span class="tl">' + label + '</span>'); b.type = 'button'; b.title = label;
      if (dis) b.disabled = true;
      b.addEventListener('click', fn); tb.appendChild(b); return b;
    }
    var ready = !!d;
    btn('↩️', 'Reply', function () { compose('reply', m); }, !ready);
    btn('⤶', 'Reply all', function () { compose('reply_all', m); }, !ready);
    btn('➡️', 'Forward', function () { compose('forward', m); }, !ready);
    // An agency mailbox with "link emails to people" on (2026-11-07): file this email on a parent, staff member or contact.
    if (a.can_link && BASE === '/email') btn('🔗', 'Link to person', function () { linkDialog(m); }, !ready);
    tb.appendChild(el('span', 'ec-tsep'));
    var graph = a.auth_type === 'microsoft' || a.auth_type === 'google', fk = folderKind(a, STATE.folder);
    btn('🗑️', fk === 'deleted' ? 'Delete permanently' : 'Delete', function () { deleteUids([m.uid]); }, !graph);
    if (graph) {
      btn('📁', 'Move', function (e) { moveMenu(e.currentTarget, [m.uid]); });
      if (fk === 'junk') btn('✅', 'Not junk', function () { runBulk('move', [m.uid], { dest: 'inbox', label: 'Moved to Inbox' }); });
      else if (fk !== 'archive') btn('🗂️', 'Archive', function () { runBulk('move', [m.uid], { dest: 'archive', label: 'Archived' }); });
    }
    btn(m.unread ? '✉️' : '📩', m.unread ? 'Mark read' : 'Mark unread', function () { toggleRead(m); }, !ready);
    btn(m.starred ? '⚑' : '⚐', m.starred ? 'Unflag' : 'Flag', function () {
      m.starred = !m.starred;
      api().post(msgPath(a, m.uid, '/action'), { account_id: a.id, folder: STATE.folder, action: m.starred ? 'star' : 'unstar' }).catch(function () {});
      markListItem(m); var newTb = toolbar(m, d); tb.replaceWith(newTb);
    }, !ready);
    return tb;
  }
  /* MARK READ / UNREAD, ONE MESSAGE (2026-11-05, Anthony: "add a mark unread function"). It lived only in the open
     message's toolbar - an unlabelled 📩 on phones - so the list row carries it too (✉️, as Outlook's row hover), plus
     Ctrl+U / Ctrl+Q as in Outlook. One path for all three: the row, the toolbar and the counts move together. */
  function toggleRead(m, to) {
    var a = STATE.acct; if (!a || !m) return;
    var want = to === undefined ? !m.unread : !!to;
    if (want === !!m.unread) return;
    m.unread = want;
    api().post(msgPath(a, m.uid, '/action'), { account_id: a.id, folder: STATE.folder, action: want ? 'unread' : 'read' }).catch(function (e) {
      m.unread = !want; bumpUnread(a, STATE.folder, want ? -1 : 1); paintRead(m);
      toast((e && e.message) || 'Could not change it.', 'info');
    });
    bumpUnread(a, STATE.folder, want ? 1 : -1);
    paintRead(m);
  }
  function paintRead(m) {
    if (!R) return;
    var it = R.list.querySelector('.ec-item[data-uid="' + (window.CSS && CSS.escape ? CSS.escape(String(m.uid)) : String(m.uid)) + '"]');
    if (it) {
      it.classList.toggle('unread', !!m.unread);
      var rb = it.querySelector('.ec-rd'); if (rb) { rb.setAttribute('aria-label', m.unread ? 'Mark read' : 'Mark unread'); rb.setAttribute('data-kttip', m.unread ? 'Mark read' : 'Mark unread'); rb.textContent = m.unread ? '📭' : '✉️'; }
    }
    // The open message's toolbar shows the opposite action now.
    if (STATE.open === m) { var tb = R.read.querySelector('.ec-tb'); if (tb) tb.replaceWith(toolbar(m, STATE.detail)); }
  }
  function openMessage(m, peek) {
    var a = STATE.acct;
    STATE.open = m; STATE.detail = null; STATE.hl = null;
    var wasUnread = m.unread; if (!peek) m.unread = false;
    markListItem(m);
    if (wasUnread && !peek) bumpUnread(a, STATE.folder, -1);
    setPane('read');
    R.read.innerHTML = '';
    R.read.appendChild(toolbar(m, null));
    var sc = el('div', 'ec-body ec-scroll'); sc.setAttribute('data-kt-scroll', '');
    sc.innerHTML = '<div class="ec-rh"><h2 class="ec-rsubj">' + esc(m.subject) + '</h2><div class="ec-empty" style="padding:10px 0;text-align:left;">Loading…</div></div>';
    R.read.appendChild(sc);
    var mine = m;
    api().get(msgPath(a, m.uid) + '&folder=' + encodeURIComponent(STATE.folder) + (peek && wasUnread ? '&peek=1' : '')).then(function (d) {
      if (STATE.open !== mine || !R) return;
      STATE.detail = d;
      if (d && typeof d.starred === 'boolean') m.starred = d.starred;
      var tb = R.read.querySelector('.ec-tb'); if (tb) tb.replaceWith(toolbar(m, d));
      sc.innerHTML = '';
      var from = (d && d.from) || { name: m.from, email: m.email };
      var list = function (arr) { return (arr || []).map(function (p) { return esc(p.name ? p.name + ' <' + p.email + '>' : p.email); }).join('; '); };
      var h = el('div', 'ec-rh');
      h.innerHTML = '<h2 class="ec-rsubj">' + esc((d && d.subject) || m.subject) + '</h2>'
        + '<div class="ec-rmeta"><span class="ec-av" data-kt-emoji="off" style="background:' + avColor(from.name || from.email) + '">' + esc(initials(from.name || from.email)) + '</span>'
        + '<div class="ec-rwho"><div><span class="n">' + esc(from.name || from.email) + '</span> <span class="a">&lt;' + esc(from.email || '') + '&gt;</span></div>'
        + (d && d.to && d.to.length ? '<div class="l">To: ' + list(d.to) + '</div>' : '')
        + (d && d.cc && d.cc.length ? '<div class="l">Cc: ' + list(d.cc) + '</div>' : '')
        + (d && d.bcc && d.bcc.length ? '<div class="l">Bcc: ' + list(d.bcc) + '</div>' : '')
        + '</div><span class="ec-rwhen">' + esc((d && d.when) || m.date) + '</span></div>';
      sc.appendChild(h);
      subPill(h, from, mine);
      var atts = (d && d.attachments) || [];
      if (atts.length) {
        var at = el('div', 'ec-atts');
        atts.forEach(function (x) {
          var b = el('button', 'ec-att', fileIcon(x.type || x.name) + '<span class="nm">' + esc(x.name) + '</span><span class="sz">' + fmtBytes(x.size) + '</span><span style="color:var(--ec-acc)">⬇</span>');
          b.type = 'button'; b.title = 'Download ' + x.name;
          b.addEventListener('click', function () { downloadAttachment(a, m.uid, x.section, x.name); });
          at.appendChild(b);
        });
        sc.appendChild(at);
      }
      var fw = el('div', 'ec-frame-wrap');
      fw.appendChild(mailFrame((d && d.html) || '<p style="color:#94A3B8">(This message has no displayable content.)</p>', 'ec-frame', true));
      sc.appendChild(fw);
    }).catch(function (e) {
      if (STATE.open !== mine || !R) return;
      sc.innerHTML = '<div class="ec-empty err">' + esc((e && e.message) || 'Could not open this message.') + '</div>';
    });
  }
  /* SUBSCRIBE / UNSUBSCRIBE ON EVERY EMAIL (2026-11-09, Anthony: "add to each email on the email client a subscribe or
     unsubscribe button that is wired up to the kiddietrac subscriber feature = quick easy buttons"). One pill beside the
     sender, wired to Platform > Website subscribers: ➕ Subscribe, or ✓ Subscriber · Unsubscribe, or Unsubscribed ·
     Subscribe again (asked first: only when they asked to be added back). Unsubscribing also suppresses the address
     for every KiddieTrac marketing email, exactly as that screen does, and never emails them. Platform admins only
     (the subscriber list is theirs); for anyone else the lookup answers 403 and nothing shows. Not on our own mail. */
  var SUB = { off: false, cache: {} };
  function subPill(h, from, mine) {
    var email = String((from && from.email) || '').trim().toLowerCase();
    if (SUB.off || !email || email.indexOf('@') < 1 || ownAddresses()[email] || /^(no-?reply|mailer-daemon|postmaster)@/.test(email)) return;
    var line = h.querySelector('.ec-rwho > div'); if (!line) return;
    var box = el('span', 'ec-sub'); box.setAttribute('data-kt-nospin', '');
    line.appendChild(box);
    var draw = function (st) {
      box.innerHTML = '';
      var btn = el('button', 'ec-sub-b'); btn.type = 'button';
      if (st.state === 'subscribed') {
        box.appendChild(el('span', 'ec-sub-t on', '✓ Subscriber'));
        btn.textContent = 'Unsubscribe'; btn.setAttribute('data-kttip', 'Take ' + email + ' off the KiddieTrac subscriber list and stop all marketing email to them');
      } else if (st.state === 'unsubscribed') {
        box.appendChild(el('span', 'ec-sub-t', 'Unsubscribed' + (st.since ? ' ' + esc(st.since) : '')));
        btn.textContent = 'Subscribe again'; btn.setAttribute('data-kttip', 'Put ' + email + ' back on the subscriber list');
      } else {
        btn.textContent = '➕ Subscribe'; btn.setAttribute('data-kttip', 'Add ' + email + ' to the KiddieTrac subscriber list');
      }
      btn.addEventListener('click', function () { act(st, btn); });
      box.appendChild(btn);
    };
    var act = async function (st, btn) {
      if (st.state === 'unsubscribed') {
        var q = 'Subscribe ' + email + ' again?';
        var ok = KT.confirm ? await KT.confirm(q, { description: 'They unsubscribed' + (st.since ? ' on ' + st.since : '') + '. Only add them back if they asked to be (Canada’s anti-spam law).' }) : window.confirm(q);
        if (!ok) return;
      }
      btn.disabled = true; var was = btn.textContent; btn.textContent = '…';
      var call = st.state === 'subscribed'
        ? api().post('/platform/site-subscribers/suppress', { email: email, name: from.name || null, note: 'From the email client', notify: false })
        : api().post('/platform/site-subscribers', { email: email, name: from.name || null });
      call.then(function () {
        var next = { state: st.state === 'subscribed' ? 'unsubscribed' : 'subscribed', since: null };
        SUB.cache[email] = next;
        toast(st.state === 'subscribed' ? email + ' unsubscribed: no more marketing email to them.' : email + ' is now a subscriber.', 'success');
        if (document.body.contains(box)) draw(next);
      }).catch(function (e) { btn.disabled = false; btn.textContent = was; toast((e && e.message) || 'Could not change it.', 'info'); });
    };
    if (SUB.cache[email]) { draw(SUB.cache[email]); return; }
    api().get('/platform/site-subscribers/status?email=' + encodeURIComponent(email)).then(function (st) {
      if (!st || !st.state) { box.remove(); return; }
      SUB.cache[email] = st;
      if (STATE.open === mine && document.body.contains(box)) draw(st);
    }).catch(function (e) {
      box.remove();
      if (e && (e.status === 403 || /administrator/i.test(e.message || ''))) SUB.off = true;   // not a platform admin: never ask again
    });
  }

  /* The message in its own sandboxed frame: its styles cannot leak into the portal, scripts cannot run, links open in
     a new tab. The frame grows to the message's height so the reading pane is the one scroll bar. */
  function mailDoc(html) {
    var out = { head: '', bodyStyle: '', htmlStyle: '', inner: html };
    try {
      if (!window.DOMParser || !/<(html|head|body|style)[\s>]/i.test(html)) return out;
      var d = new DOMParser().parseFromString(html, 'text/html');   // inert: nothing in it runs or loads
      [].forEach.call(d.querySelectorAll('style'), function (st) { out.head += '<style>' + st.textContent.replace(/<\/style/gi, '') + '</style>'; st.remove(); });
      var b = d.body, css = '';
      if (b) {
        var bg = b.getAttribute('bgcolor'), tx = b.getAttribute('text'), bgi = b.getAttribute('background');
        css = (bg ? 'background-color:' + bg + ';' : '') + (bgi && /^https?:/i.test(bgi) ? 'background-image:url(' + bgi.replace(/[()"']/g, '') + ');' : '') + (tx ? 'color:' + tx + ';' : '') + (b.getAttribute('style') || '');
        out.inner = b.innerHTML;
      }
      if (css) {
        var t = document.createElement('div'); t.style.cssText = css;
        out.bodyStyle = t.style.cssText.replace(/"/g, '&quot;');
        if (t.style.backgroundColor) out.htmlStyle = 'background-color:' + t.style.backgroundColor.replace(/"/g, '&quot;');
      }
    } catch (e) { out.inner = html; }
    return out;
  }
  function mailFrame(html, cls, autoHeight) {
    var f = document.createElement('iframe');
    f.className = cls || '';
    f.setAttribute('data-kt-keep-height', '');
    f.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox allow-same-origin');
    f.setAttribute('referrerpolicy', 'no-referrer');
    /* THE EMAIL'S OWN LOOK (2026-11-04, Anthony: "dark emails or backgrounds are not showing up correctly"). A message
       arrives as a whole document: its <style> blocks and its <body bgcolor / style / text> carry the background and
       the text colours. Dropped into our body, the parser threw the body's attributes away, so a dark newsletter
       became light-on-white. Its styles go into the frame's head (after ours, so they win) and its body's look onto
       ours - and onto <html>, so the padding around it matches. */
    var doc = mailDoc(html);
    if (window.KT && KT.frameLinks) KT.frameLinks(f);   // links open in the browser, in the app too (kt-doc-viewer)
    f.srcdoc = '<!doctype html><html' + (doc.htmlStyle ? ' style="' + doc.htmlStyle + '"' : '') + '><head><meta charset="utf-8"><base target="_blank"><style>html,body{margin:0;background:#fff;}body{padding:16px 18px;font:14px/1.55 "Segoe UI",Calibri,Arial,sans-serif;color:#1F2937;word-wrap:break-word;overflow-wrap:anywhere;}img{max-width:100%;height:auto;}table{max-width:100%;}pre{white-space:pre-wrap;}blockquote{margin:0 0 0 6px;padding-left:10px;border-left:3px solid #D5DCE4;}</style>' + doc.head + '</head><body' + (doc.bodyStyle ? ' style="' + doc.bodyStyle + '"' : '') + '>' + doc.inner + '</body></html>';
    if (autoHeight) {
      f.addEventListener('load', function () {
        try {
          var doc = f.contentDocument; if (!doc) return;
          /* THE EMAIL'S OWN BACKGROUND TO THE EDGES (2026-11-05, Anthony: "KiddieTrac crash email not showing up dark
             properly in the email client"). The frame follows the computer's dark setting, so an email with its own
             dark mode (ours all have one) turns dark - but it sat in our white frame: a white ring from the frame's
             padding, and a white band under it where the "Follow us" row and the space below fall on our <body>.
             The page colour the email really shows - its full-width outer container if it paints one (a dark mode
             often recolours that box and not <body>), else its <body> as its own CSS leaves it in this mode - is
             carried onto <body>, <html> and the frame, now and again whenever the mode changes. An email with no
             colour of its own stays on white paper, as before. */
          var clear = function (v) { return !v || /^(transparent|rgba\(0, 0, 0, 0\))$/.test(v); };
          var edges = function () {
            try {
              var de = doc.documentElement, b = doc.body; if (!b) return;
              de.style.backgroundColor = ''; b.style.backgroundColor = doc.__ktOwnBg || '';   // the email's own look first
              var w = b.clientWidth, c = b.firstElementChild, page = '';
              while (c && /^(STYLE|META|LINK|SCRIPT|BR)$/.test(c.tagName)) c = c.nextElementSibling;
              if (c && w && c.getBoundingClientRect().width >= w * 0.9) {
                var v = getComputedStyle(c).backgroundColor;
                if (!clear(v)) page = v; else if (c.getAttribute('bgcolor')) page = c.getAttribute('bgcolor');
              }
              if (!page) page = getComputedStyle(b).backgroundColor;
              if (clear(page)) page = '#fff';
              de.style.backgroundColor = page; b.style.backgroundColor = page;
              f.style.backgroundColor = page; f.parentElement.style.backgroundColor = page;
            } catch (e) {}
          };
          doc.__ktOwnBg = doc.body && doc.body.style.backgroundColor || '';   // the email's own <body> colour (mailDoc) wins
          edges();
          try { var mq = f.contentWindow.matchMedia('(prefers-color-scheme: dark)'); if (mq && mq.addEventListener) mq.addEventListener('change', edges); } catch (e) {}
          var fit = function () {
            var de = doc.documentElement, b = doc.body; if (!b) return;
            b.style.zoom = '';
            var avail = f.clientWidth, wide = de.scrollWidth;
            if (avail > 0 && wide > avail + 4) b.style.zoom = String(Math.max(0.3, avail / wide));
            f.style.height = Math.max(120, de.scrollHeight + 4) + 'px';
          };
          fit();
          if (window.ResizeObserver) new ResizeObserver(fit).observe(doc.body);
          [].forEach.call(doc.images || [], function (im) { im.addEventListener('load', fit); });
        } catch (e) { f.style.height = '60vh'; }
      });
    }
    return f;
  }
  function fileIcon(t) { t = String(t || '').toLowerCase(); if (/pdf/.test(t)) return '📄'; if (/(jpe?g|png|gif|webp|heic|image)/.test(t)) return '🖼️'; if (/(zip|rar|7z)/.test(t)) return '🗜️'; if (/(docx?|word|rtf)/.test(t)) return '📝'; if (/(xlsx?|sheet|csv)/.test(t)) return '📊'; if (/(pptx?|present)/.test(t)) return '📽️'; return '📎'; }
  function downloadAttachment(a, uid, section, name) {
    var token = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var base = (KT && KT.API_BASE) || (window.KT_CONFIG && window.KT_CONFIG.apiBase) || 'https://api.kiddietrac.com/api/v1';
    var url = base + msgPath(a, uid, '/attachment') + '&folder=' + encodeURIComponent(STATE.folder) + '&section=' + encodeURIComponent(section);
    toast('Downloading ' + (name || 'attachment') + '…', 'info');
    fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
      .then(function (blob) {
        var u = URL.createObjectURL(blob);
        var x = document.createElement('a'); x.href = u; x.download = name || 'attachment';
        document.body.appendChild(x); x.click();
        setTimeout(function () { URL.revokeObjectURL(u); x.remove(); }, 1500);
      }).catch(function (e) { toast('Could not download: ' + ((e && e.message) || 'error'), 'info'); });
  }
  // ── Selection, bulk actions, Undo (2026-11-03) ───────────────────────
  function folderKind(a, key) {
    var f = folderInfo(a, key); var n = String((f && f.label) || key).toLowerCase();
    if (key === 'INBOX') return 'inbox';
    if (/deleted|trash|bin/.test(n)) return 'deleted';
    if (/junk|spam/.test(n)) return 'junk';
    if (/archive/.test(n)) return 'archive';
    if (/draft/.test(n)) return 'drafts';
    if (/sent/.test(n)) return 'sent';
    return 'other';
  }
  function selCount() { return Object.keys(STATE.sel).length; }
  function selUids() { return STATE.messages.filter(function (m) { return STATE.sel[m.uid]; }).map(function (m) { return m.uid; }); }
  function paintSel() {
    if (!R) return;
    [].forEach.call(R.list.querySelectorAll('.ec-item'), function (it) {
      var on = !!STATE.sel[it.getAttribute('data-uid')];
      it.classList.toggle('sel', on);
      var ck = it.querySelector('.ec-ck'); if (ck) ck.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    drawSelBar();
  }
  function clearSel(silent) { STATE.sel = {}; STATE.anchor = null; if (!silent) paintSel(); else if (R) R.root.classList.remove('ec-selecting'); }
  function toggleSel(m) { if (STATE.sel[m.uid]) delete STATE.sel[m.uid]; else STATE.sel[m.uid] = 1; STATE.anchor = m.uid; paintSel(); }
  function rangeSel(m) {
    var ids = STATE.messages.map(function (x) { return x.uid; });
    var i = ids.indexOf(STATE.anchor), j = ids.indexOf(m.uid);
    if (i < 0) { toggleSel(m); return; }
    var lo = Math.min(i, j), hi = Math.max(i, j);
    for (var k = lo; k <= hi; k++) STATE.sel[ids[k]] = 1;
    paintSel();
  }
  function selectAll() {
    if (!STATE.messages.length) return;
    if (selCount() === STATE.messages.length) clearSel(); else { STATE.messages.forEach(function (m) { STATE.sel[m.uid] = 1; }); paintSel(); }
  }
  var pressAt = 0;
  function wireItem(it, m) {
    var pressT = null, pressed = false, sx = 0, sy = 0;
    it.addEventListener('touchstart', function (e) {
      pressed = false; var t = e.touches[0]; sx = t.clientX; sy = t.clientY;
      clearTimeout(pressT);
      pressT = setTimeout(function () { pressed = true; pressAt = Date.now(); toggleSel(m); try { navigator.vibrate && navigator.vibrate(12); } catch (x) {} }, 480);
    }, { passive: true });
    it.addEventListener('touchmove', function (e) { var t = e.touches[0]; if (Math.abs(t.clientX - sx) > 10 || Math.abs(t.clientY - sy) > 10) clearTimeout(pressT); }, { passive: true });
    it.addEventListener('touchend', function () { clearTimeout(pressT); }, { passive: true });
    it.addEventListener('touchcancel', function () { clearTimeout(pressT); }, { passive: true });
    it.addEventListener('contextmenu', function (e) { if (pressed || selCount()) e.preventDefault(); });
    var rd = it.querySelector('.ec-rd');
    if (rd) rd.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); toggleRead(m); } });
    it.addEventListener('click', function (e) {
      if (pressed) { pressed = false; e.preventDefault(); return; }
      // The selection bar appears as a long-press lands and pushes the rows down: the click the browser may send
      // when the finger lifts must not toggle the row that slid under it.
      if (Date.now() - pressAt < 700) { e.preventDefault(); return; }
      if (e.target.closest('.ec-rd')) { e.preventDefault(); toggleRead(m); return; }
      if (e.target.closest('.ec-ck')) { e.preventDefault(); if (e.shiftKey && STATE.anchor) rangeSel(m); else toggleSel(m); return; }
      if (e.shiftKey && STATE.anchor) { e.preventDefault(); rangeSel(m); return; }
      if (e.ctrlKey || e.metaKey) { e.preventDefault(); toggleSel(m); return; }
      if (selCount() && isPhone()) { toggleSel(m); return; }
      if (selCount()) clearSel();
      STATE.anchor = m.uid;
      openMessage(m);
    });
  }
  function drawSelBar() {
    if (!R || !R.selbar) return;
    var n = selCount(), a = STATE.acct;
    R.root.classList.toggle('ec-selecting', n > 0);
    var tot = STATE.messages.length;
    R.ckall.setAttribute('aria-checked', !n ? 'false' : (n >= tot ? 'true' : 'mixed'));
    R.selbar.innerHTML = '';
    if (!n || !a) return;
    var graph = a.auth_type === 'microsoft' || a.auth_type === 'google', fk = folderKind(a, STATE.folder);
    var all = el('span', 'ec-ckall'); all.setAttribute('role', 'checkbox'); all.setAttribute('tabindex', '0'); all.title = 'Select all';
    all.setAttribute('aria-label', 'Select all messages'); all.setAttribute('aria-checked', n >= tot ? 'true' : 'mixed');
    all.addEventListener('click', selectAll);
    R.selbar.appendChild(all);
    R.selbar.appendChild(el('span', 'n', n + ' selected'));
    function sb(icon, label, fn, cls) {
      var b = el('button', 'ec-sb' + (cls ? ' ' + cls : ''), '<span>' + icon + '</span><span class="tl">' + label + '</span>'); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label);
      b.addEventListener('click', fn); R.selbar.appendChild(b); return b;
    }
    var ids = function () { return selUids(); };
    if (graph) {
      sb('🗑️', fk === 'deleted' ? 'Delete permanently' : 'Delete', function () { deleteUids(ids()); }, 'danger');
      sb('📁', 'Move to', function (e) { moveMenu(e.currentTarget, ids()); });
      if (fk === 'junk') sb('✅', 'Not junk', function () { runBulk('move', ids(), { dest: 'inbox', label: 'Moved to Inbox' }); });
      else if (fk !== 'archive') sb('🗂️', 'Archive', function () { runBulk('move', ids(), { dest: 'archive', label: 'Archived' }); });
    }
    var sel = STATE.messages.filter(function (m) { return STATE.sel[m.uid]; });
    var anyUnread = sel.some(function (m) { return m.unread; }), anyFlag = sel.some(function (m) { return m.starred; });
    if (anyUnread) sb('✉️', 'Mark read', function () { runBulk('read', ids()); });
    else sb('📩', 'Mark unread', function () { runBulk('unread', ids()); });
    if (anyFlag) sb('⚐', 'Unflag', function () { runBulk('unstar', ids()); });
    else sb('⚑', 'Flag', function () { runBulk('star', ids()); });
    R.selbar.appendChild(el('span', 'sp'));
    sb('✕', 'Clear selection', function () { clearSel(); });
  }
  function closeMenu() { var m = document.querySelector('.ec-menu'); if (m) m.remove(); document.removeEventListener('mousedown', menuAway, true); document.removeEventListener('touchstart', menuAway, true); }
  function menuAway(e) { if (!e.target.closest('.ec-menu')) closeMenu(); }
  function moveMenu(anchorBtn, uids) {
    closeMenu();
    var a = STATE.acct; if (!a || !uids.length) return;
    var list = ((STATE.folders[a.id] || {}).list || []).filter(function (f) { return f.key !== STATE.folder && !/^(outbox|sync issues|conversation history)/i.test(f.label); });
    var m = el('div', 'ec-menu'); m.setAttribute('role', 'menu');
    m.appendChild(el('div', 'hd', 'Move ' + (uids.length > 1 ? uids.length + ' messages' : 'to')));
    list.forEach(function (f) {
      var b = el('button', '', '<span>' + (ICON[String(f.label).toLowerCase()] || f.special || '📁') + '</span><span>' + esc(f.label) + '</span>'); b.type = 'button'; b.setAttribute('role', 'menuitem');
      b.addEventListener('click', function () { closeMenu(); runBulk('move', uids, { dest: f.key, label: 'Moved to ' + f.label }); });
      m.appendChild(b);
    });
    document.body.appendChild(m);
    var r = anchorBtn.getBoundingClientRect(), mw = m.offsetWidth, mh = m.offsetHeight;
    var left = Math.max(8, Math.min(r.left, window.innerWidth - mw - 8));
    var top = r.bottom + 4; if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 4);
    m.style.left = left + 'px'; m.style.top = top + 'px';
    var first = m.querySelector('button'); if (first) first.focus();
    m.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); closeMenu(); anchorBtn.focus(); } });
    setTimeout(function () { document.addEventListener('mousedown', menuAway, true); document.addEventListener('touchstart', menuAway, true); }, 0);
  }
  /* LINK TO PERSON (2026-11-07, Anthony: "allow agency mailboxes ... to link emails to parents, educators and any other
     user's accounts for tracking purposes"). Who this email is linked to (✕ unlinks - kept in the audit log), the
     people its addresses belong to (one tap), and a search of the agency's live parents, staff and contacts. The email
     then shows under ✉️ Emails on that person's record (App\Support\PersonMail). */
  var LINK_AS = { guardian: 'Parent', staff: 'Staff', contact: 'Contact' };
  function linkDialog(m) {
    var a = STATE.acct; if (!a || !m) return;
    var path = function (suffix) { return msgPath(a, m.uid, suffix) + '&folder=' + encodeURIComponent(STATE.folder); };
    var body = el('div'); body.setAttribute('data-kt-btn-free', ''); body.setAttribute('data-kt-nospin', '');
    body.style.cssText = 'display:flex;flex-direction:column;gap:12px;font-size:13.5px;color:#0F172A;';
    var linked = el('div'), sug = el('div'), res = el('div');
    var q = el('input'); q.type = 'search'; q.placeholder = 'Search parents, staff and contacts by name or email';
    q.setAttribute('aria-label', 'Search people');
    q.style.cssText = 'width:100%;box-sizing:border-box;height:32px;border:1px solid #CBD5E1;border-radius:8px;padding:0 10px;font:inherit;';
    var note = el('div', null, 'Linked emails show under <b>✉️ Emails</b> on the person\'s record. Every link and unlink is kept in the audit log.');
    note.style.cssText = 'font-size:12px;color:#64748B;line-height:1.45;';
    function head(t) { var h = el('div', null, esc(t)); h.style.cssText = 'font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#64748B;margin-bottom:6px;'; return h; }
    function row(p, label, fn, danger) {
      var r = el('div'); r.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid #E2E8F0;border-radius:8px;margin-bottom:6px;background:#fff;';
      var who = el('div', null, '<div style="font-weight:700;">' + esc(p.name || '(no name)') + '</div><div style="font-size:12px;color:#64748B;">'
        + esc(LINK_AS[p.as] || '') + (p.email ? ' · ' + esc(p.email) : '') + (p.source ? ' · ' + (p.source === 'auto' ? 'matched by address' : 'linked by hand') : '') + '</div>');
      who.style.cssText = 'flex:1;min-width:0;overflow-wrap:anywhere;';
      var b = el('button', 'ec-sbtn', label); b.type = 'button';
      b.style.cssText = 'height:28px;padding:0 12px;border-radius:7px;font-weight:700;cursor:pointer;' + (danger ? 'background:#fff;color:#B91C1C;border:1px solid #FCA5A5;' : 'background:#1F6080;color:#fff;border:1px solid #1F6080;');
      b.addEventListener('click', function () { b.disabled = true; fn().catch(function (e) { b.disabled = false; toast((e && e.message) || 'Could not save that.', 'info'); }); });
      r.appendChild(who); r.appendChild(b); return r;
    }
    function link(p) {
      return api().post(path('/links'), { account_id: a.id, mid: /^\d+$/.test(String(m.uid)) ? null : m.uid, folder: STATE.folder, person_type: p.type, person_id: p.id })
        .then(function (r) { toast((r && r.already ? 'Already linked to ' : 'Linked to ') + (p.name || 'them'), 'success'); load(); });
    }
    function load() {
      return api().get(path('/links')).then(function (r) {
        linked.innerHTML = ''; sug.innerHTML = '';
        var have = {};
        linked.appendChild(head('Linked to'));
        if (!(r.linked || []).length) { var none = el('div', null, 'Not linked to anyone yet.'); none.style.cssText = 'color:#64748B;font-size:13px;'; linked.appendChild(none); }
        (r.linked || []).forEach(function (l) {
          have[l.type + ':' + l.person_id] = true;
          linked.appendChild(row({ name: l.name, as: l.as, source: l.source }, 'Unlink', function () {
            return api().delete('/email/links/' + l.id).then(function () { toast('Unlinked ' + (l.name || ''), 'info'); load(); });
          }, true));
        });
        if ((r.suggest || []).length) {
          sug.appendChild(head('On this email'));
          r.suggest.forEach(function (p) { sug.appendChild(row(p, '🔗 Link', function () { return link(p); })); });
        }
        body.__have = have;
      }).catch(function (e) { linked.innerHTML = ''; linked.appendChild(el('div', null, esc((e && e.message) || 'Could not load the links.'))); });
    }
    var t = null;
    q.addEventListener('input', function () {
      clearTimeout(t);
      var v = q.value.trim();
      if (v.length < 2) { res.innerHTML = ''; return; }
      t = setTimeout(function () {
        api().get(BASE + '/link-people?account_id=' + a.id + '&q=' + encodeURIComponent(v)).then(function (r) {
          if (q.value.trim() !== v) return;
          res.innerHTML = '';
          var list = (r.people || []).filter(function (p) { return !(body.__have || {})[p.type + ':' + p.id]; });
          if (!list.length) { var n = el('div', null, 'No one in this agency matches.'); n.style.cssText = 'color:#64748B;font-size:13px;'; res.appendChild(n); return; }
          list.forEach(function (p) { res.appendChild(row(p, '🔗 Link', function () { return link(p).then(function () { q.value = ''; res.innerHTML = ''; }); })); });
        }).catch(function () {});
      }, 250);
    });
    var find = el('div'); find.appendChild(head('Find someone')); find.appendChild(q); res.style.marginTop = '8px'; find.appendChild(res);
    body.appendChild(linked); body.appendChild(sug); body.appendChild(find); body.appendChild(note);
    linked.appendChild(el('div', null, 'Loading…'));
    Shell.Modal.open({ title: '🔗 Link to person — ' + (m.subject || ''), body: body });
    load();
    setTimeout(function () { try { q.focus(); } catch (e) {} }, 80);
  }
  function deleteUids(uids) {
    var a = STATE.acct; if (!a || !uids.length) return;
    if (folderKind(a, STATE.folder) !== 'deleted') { runBulk('delete', uids, { label: 'Moved to Deleted Items' }); return; }
    // From Deleted Items it goes for good: ask first, in the house popup.
    var body = el('div', 'ec-cmp'); body.setAttribute('data-kt-btn-free', ''); body.setAttribute('data-kt-nospin', '');
    body.innerHTML = '<p style="margin:0 0 14px;font-size:14px;line-height:1.5;">' + (uids.length > 1 ? 'These <b>' + uids.length + ' messages</b> will be' : 'This message will be')
      + ' deleted for good from ' + esc(a.email_address) + '. This cannot be undone here.</p>';
    var row = el('div'); row.style.cssText = 'display:flex;justify-content:flex-end;gap:8px;';
    var no = el('button', 'ec-sbtn', 'Cancel'); no.type = 'button';
    var yes = el('button', 'ec-sbtn', '🗑️ Delete for good'); yes.type = 'button'; yes.style.cssText = 'background:#B91C1C;border-color:#B91C1C;color:#fff;';
    no.addEventListener('click', function () { Shell.Modal.close(); });
    yes.addEventListener('click', function () { Shell.Modal.close(); runBulk('purge', uids, { label: 'Deleted for good' }); });
    row.appendChild(no); row.appendChild(yes); body.appendChild(row);
    Shell.Modal.open({ title: '🗑️ Delete permanently?', body: body });
    setTimeout(function () { no.focus(); }, 60);
  }
  /* One path for every message action, one message or many. Moves and deletes leave the list at once; flags and
     read state change at once; anything Microsoft refuses is put back and said in words. */
  function runBulk(action, uids, opt) {
    opt = opt || {};
    var a = STATE.acct; if (!a || !uids.length) return Promise.resolve();
    var folder = STATE.folder, isGone = action === 'delete' || action === 'move' || action === 'purge';
    var before = STATE.messages.slice();
    var hit = STATE.messages.filter(function (m) { return uids.indexOf(m.uid) > -1; });
    var snap = hit.map(function (m) { return { m: m, unread: m.unread, starred: m.starred }; });
    var openGone = isGone && STATE.open && uids.indexOf(STATE.open.uid) > -1;
    var nextOpen = null;
    if (openGone) { var i = before.indexOf(STATE.open); for (var k = i + 1; k < before.length && !nextOpen; k++) if (uids.indexOf(before[k].uid) < 0) nextOpen = before[k]; for (k = i - 1; k >= 0 && !nextOpen; k--) if (uids.indexOf(before[k].uid) < 0) nextOpen = before[k]; }
    var unreadDelta = 0;
    if (isGone) {
      STATE.messages = STATE.messages.filter(function (m) { return uids.indexOf(m.uid) < 0; });
      hit.forEach(function (m) { if (m.unread) unreadDelta--; });
      uids.forEach(function (u) { delete STATE.sel[u]; });
      if (openGone) { if (nextOpen && !isPhone() && uids.length === 1) openMessage(nextOpen); else { emptyReading(); setPane('list'); } }
    } else {
      hit.forEach(function (m) {
        if (action === 'read' && m.unread) { m.unread = false; unreadDelta--; }
        if (action === 'unread' && !m.unread) { m.unread = true; unreadDelta++; }
        if (action === 'star') m.starred = true;
        if (action === 'unstar') m.starred = false;
      });
    }
    if (unreadDelta) bumpUnread(a, folder, unreadDelta);
    drawList();
    if (STATE.open && !isGone && uids.indexOf(STATE.open.uid) > -1) { var tb = R.read.querySelector('.ec-tb'); if (tb) tb.replaceWith(toolbar(STATE.open, STATE.detail)); }
    var body = { account_id: a.id, folder: folder, action: action, ids: uids };
    if (opt.dest) body.dest = opt.dest;
    function putBack(ids) {
      var back = {}; ids.forEach(function (u) { back[u] = 1; });
      snap.forEach(function (x) { if (!back[x.m.uid]) return; x.m.unread = x.unread; x.m.starred = x.starred; });
      if (isGone && STATE.acct === a && STATE.folder === folder) {
        var keep = {}; STATE.messages.forEach(function (m) { keep[m.uid] = 1; });
        STATE.messages = before.filter(function (m) { return keep[m.uid] || back[m.uid]; });
      }
      drawList();
    }
    return api().post(BASE + '/messages/bulk', body).then(function (r) {
      var failed = (r && r.failed) || [], done = (r && r.done) || [];
      if (failed.length) {
        putBack(failed.map(function (f) { return f.id; }));
        var why = failed[0].message || 'Microsoft could not change it right now.';
        toast((done.length ? done.length + ' done; ' + failed.length + ' could not be changed. ' : (failed.length > 1 ? failed.length + ' messages could not be changed. ' : 'That message could not be changed. ')) + why, 'info');
      }
      if (isGone && done.length && action !== 'purge') {
        var moved = done.map(function (d) { return d.new_id || d.id; });
        undoBar((opt.label || 'Done') + (done.length > 1 ? ' · ' + done.length + ' messages' : ''), function () {
          return api().post(BASE + '/messages/bulk', { account_id: a.id, folder: opt.dest || 'deleteditems', action: 'move', dest: folder, ids: moved }).then(function (u) {
            var nf = (u && u.failed) || [];
            toast(nf.length ? nf.length + ' could not be put back. ' + (nf[0].message || '') : 'Put back.', nf.length ? 'info' : 'success');
            if (STATE.acct === a && STATE.folder === folder) loadList(false, true);
            loadFolders(a).then(drawTree);
          }).catch(function (e) { toast(friendly(e, 'Could not put them back.'), 'info'); });
        });
      } else if (isGone && done.length) {
        toast(done.length > 1 ? done.length + ' messages deleted for good.' : 'Deleted for good.', 'success');
      }
      loadFolders(a).then(drawTree);
    }).catch(function (e) {
      putBack(uids);
      if (unreadDelta) bumpUnread(a, folder, -unreadDelta);
      toast(friendly(e, 'Nothing was changed - please try again.'), 'info');
    });
  }
  function friendly(e, fallback) {
    var m = (e && e.message) || '';
    if (!m || /^(HTTP|Request failed|Failed to fetch|NetworkError|TypeError|SyntaxError)|status code|<html|exception/i.test(m)) return fallback;
    return m;
  }
  var undoTimer = null;
  function undoBar(text, onUndo) {
    var old = document.querySelector('.ec-undo'); if (old) old.remove();
    clearTimeout(undoTimer);
    var b = el('div', 'ec-undo'); b.setAttribute('role', 'status'); b.setAttribute('data-kt-nospin', '');
    b.appendChild(el('span', 'tx', esc(text)));
    var u = el('button', '', 'Undo'); u.type = 'button';
    var x = el('button', 'x', '✕'); x.type = 'button'; x.setAttribute('aria-label', 'Dismiss');
    u.addEventListener('click', function () { b.remove(); clearTimeout(undoTimer); onUndo(); });
    x.addEventListener('click', function () { b.remove(); clearTimeout(undoTimer); });
    b.appendChild(u); b.appendChild(x);
    document.body.appendChild(b);
    undoTimer = setTimeout(function () { if (b.isConnected) b.remove(); }, 8000);
  }
  var keysBound = false;
  function bindKeys() {
    if (keysBound) return; keysBound = true;
    document.addEventListener('keydown', function (e) {
      if (!R || !R.root.isConnected || isPhone()) return;
      var t = e.target;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
      if (document.querySelector('.modal-backdrop, .modal.show, [role="dialog"]:not([hidden])') && !R.root.contains(t)) return;
      if (e.key === 'Escape') { if (document.querySelector('.ec-menu')) { closeMenu(); return; } if (selCount()) { clearSel(); e.preventDefault(); } return; }
      if (e.key === 'Delete') {
        var ids = selCount() ? selUids() : (STATE.open ? [STATE.open.uid] : []);
        if (ids.length && STATE.acct && (STATE.acct.auth_type === 'microsoft' || STATE.acct.auth_type === 'google')) { e.preventDefault(); deleteUids(ids); }
        return;
      }
      // Outlook's keys: Ctrl+U mark unread, Ctrl+Q mark read - the selected messages, else the open one.
      if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && /^[uUqQ]$/.test(e.key)) {
        var unread = /^[uU]$/.test(e.key);
        if (selCount()) { e.preventDefault(); runBulk(unread ? 'unread' : 'read', selUids()); return; }
        if (STATE.open) { e.preventDefault(); toggleRead(STATE.open, unread); return; }
        return;
      }
      if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A') && R.list.contains(t)) { e.preventDefault(); STATE.messages.forEach(function (m) { STATE.sel[m.uid] = 1; }); paintSel(); }
    });
  }
  function deleteMessage(m, btn) {
    var a = STATE.acct;
    var tl = btn.querySelector('.tl');
    if (btn.dataset.armed !== '1') {
      btn.dataset.armed = '1'; btn.title = 'Click again to move it to Deleted Items';
      if (tl) tl.textContent = 'Click again to delete'; else toast('Tap 🗑️ again to delete it.', 'info');
      setTimeout(function () { if (btn.isConnected) { btn.dataset.armed = ''; if (tl) tl.textContent = 'Delete'; } }, 4000);
      return;
    }
    btn.disabled = true;
    api().post(msgPath(a, m.uid, '/action'), { account_id: a.id, folder: STATE.folder, action: 'delete' }).then(function () {
      var i = STATE.messages.indexOf(m); var next = STATE.messages[i + 1] || STATE.messages[i - 1] || null;
      STATE.messages = STATE.messages.filter(function (x) { return x !== m; });
      if (m.unread) bumpUnread(a, STATE.folder, -1);
      drawList(); toast('Moved to Deleted Items', 'success');
      if (next && !isPhone()) openMessage(next); else { emptyReading(); setPane('list'); }
      loadFolders(a).then(drawTree);
    }).catch(function (e) { btn.disabled = false; toast((e && e.message) || 'Could not delete it.', 'info'); });
  }

  // ── Polling + the bell ──────────────────────────────────────────────
  var poll = null;
  function refreshAll(loud) {
    if (!R || !R.root.isConnected || !STATE.accounts.length) return;
    loadAccounts().then(function () {
      drawBanners();
      if (STATE.acct) STATE.acct = acctById(STATE.acct.id) || STATE.accounts[0];
      return Promise.all(STATE.accounts.map(function (a) { return loadFolders(a); }));
    }).then(function () {
      drawTree();
      // Page one, no search: fold new mail in without losing the place or the open message.
      if (loud || (!STATE.skip && !STATE.q)) loadList(false, !loud);
    }).catch(function () {});
  }
  function startPolling() {
    if (poll) clearInterval(poll);
    poll = setInterval(function () {
      if (!R || !R.root.isConnected) { clearInterval(poll); poll = null; return; }
      if (document.hidden) return;
      refreshAll(false);
    }, 60000);
  }
  // A new-mail bell (kiddietrac:mail-watch) for this screen: refresh now rather than at the next minute.
  window.addEventListener('kt:notification', function (e) {
    var d = e && e.detail; if (!d || !R || !R.root.isConnected) return;
    if (d.type === 'email' || d.type === 'email_reconnect') setTimeout(function () { refreshAll(false); }, 400);
  });

  // ══════════════════════════════════════════════════════════════════
  //  COMPOSE / REPLY / REPLY ALL / FORWARD
  // ══════════════════════════════════════════════════════════════════
  function chipField(initial, onChange) {
    var box = el('div', 'ec-chips');
    var input = el('input', 'ec-chip-in'); input.type = 'email'; input.setAttribute('autocomplete', 'off'); input.setAttribute('data-kt-nospell', '');
    input.setAttribute('list', 'ec-known-addr');
    var items = [];
    function valid(x) { return /^[^\s@<>(),;:"]+@[^\s@<>(),;:"]+\.[^\s@<>(),;:"]+$/.test(x); }
    function draw() {
      [].forEach.call(box.querySelectorAll('.ec-chip'), function (c) { c.remove(); });
      items.forEach(function (x, i) {
        var c = el('span', 'ec-chip' + (valid(x.email) ? '' : ' bad'), '<span class="tx">' + esc(x.name ? x.name + ' <' + x.email + '>' : x.email) + '</span>');
        c.title = x.email + (valid(x.email) ? '' : ' — not a valid address');
        var rm = el('button', '', '✕'); rm.type = 'button'; rm.title = 'Remove'; rm.setAttribute('aria-label', 'Remove ' + x.email);
        rm.addEventListener('click', function (e) { e.stopPropagation(); items.splice(i, 1); draw(); onChange && onChange(); });
        c.appendChild(rm); box.insertBefore(c, input);
      });
    }
    function add(raw) {
      String(raw || '').split(/[,;\n]+/).forEach(function (part) {
        part = part.trim(); if (!part) return;
        var m = part.match(/^(.*?)<([^>]+)>$/);
        var email = (m ? m[2] : part).trim().replace(/^mailto:/i, ''), name = m ? m[1].trim().replace(/^"|"$/g, '') : '';
        if (items.some(function (x) { return x.email.toLowerCase() === email.toLowerCase(); })) return;
        items.push({ email: email, name: name });
      });
      draw(); onChange && onChange();
    }
    input.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === 'Tab') && input.value.trim()) { e.preventDefault(); add(input.value); input.value = ''; }
      else if (e.key === 'Backspace' && !input.value && items.length) { items.pop(); draw(); onChange && onChange(); }
    });
    input.addEventListener('blur', function () { if (input.value.trim()) { add(input.value); input.value = ''; } });
    input.addEventListener('paste', function (e) { var t = (e.clipboardData || window.clipboardData).getData('text'); if (/[,;\n]/.test(t)) { e.preventDefault(); add(t); } });
    input.addEventListener('input', function () { onChange && onChange(); });
    box.addEventListener('click', function () { input.focus(); });
    box.appendChild(input);
    (initial || []).forEach(function (p) { items.push({ email: p.email, name: p.name || '' }); });
    draw();
    return {
      el: box, input: input,
      emails: function () { if (input.value.trim()) { add(input.value); input.value = ''; } return items.map(function (x) { return x.email; }); },
      bad: function () { return items.filter(function (x) { return !valid(x.email); }).map(function (x) { return x.email; }); },
      count: function () { return items.length + (input.value.trim() ? 1 : 0); },
      focus: function () { input.focus(); }
    };
  }
  function knownAddresses() {
    var dl = document.getElementById('ec-known-addr');
    if (!dl) { dl = document.createElement('datalist'); dl.id = 'ec-known-addr'; document.body.appendChild(dl); }
    var seen = {}, out = [];
    STATE.messages.forEach(function (m) { if (m.email && !seen[m.email.toLowerCase()]) { seen[m.email.toLowerCase()] = 1; out.push('<option value="' + esc(m.email) + '">' + esc(m.from || '') + '</option>'); } });
    dl.innerHTML = out.slice(0, 200).join('');
  }
  function sigHtml(a) {
    if (!a || a.signature_enabled === false || !a.signature_html) return '';
    var s = String(a.signature_html);
    return /<[a-z][^>]*>/i.test(s) ? s : esc(s).replace(/\n/g, '<br>');
  }
  function prefix(mode, subj) {
    subj = String(subj || '');
    if (mode === 'forward') return /^(fw|fwd):/i.test(subj) ? subj : 'Fw: ' + subj;
    return /^re:/i.test(subj) ? subj : 'Re: ' + subj;
  }

  function compose(mode, m) {
    var src = STATE.acct;
    var d = m ? STATE.detail : null;
    var accts = sendable();
    if (!accts.length) {
      /* Every mailbox signed in with Microsoft or Google sends (2026-11-09). One connected before that needs one
         Reconnect to add the permission; an IMAP mailbox is read-only here. */
      var re = (src && src.send_needs_reconnect) ? src : STATE.accounts.filter(function (a) { return a.send_needs_reconnect; })[0];
      if (re && KT.confirm) {
        KT.confirm({ title: 'Allow sending from ' + re.email_address + '?', description: 'This mailbox was connected before sending was added. Sign in with Microsoft once more and it can reply, forward and send from here.', okLabel: 'Sign in with Microsoft' })
          .then(function (ok) { if (ok) startMicrosoft(re.id, null, null, 'microsoft'); });
        return;
      }
      toast('Sending works from mailboxes signed in with Microsoft or Google. Add one in Manage accounts, or answer this one from its own mail app.', 'info');
      return;
    }
    if (m && !d) { toast('Still opening the message — one moment.', 'info'); return; }
    var from = (src && src.can_send) ? src : (accts.filter(function (a) { return a.is_default; })[0] || accts[0]);
    var own = ownAddresses();
    var notOwn = function (p) { return p && p.email && !own[String(p.email).toLowerCase()]; };
    var to = [], cc = [];
    if (mode === 'reply' || mode === 'reply_all') {
      var rt = (d.reply_to && d.reply_to.length) ? d.reply_to : (d.from ? [d.from] : []);
      var sentByUs = d.from && own[String(d.from.email).toLowerCase()];
      if (sentByUs) rt = d.to || [];                         // replying to our own sent mail: back to whom it went
      to = rt.filter(notOwn);
      if (mode === 'reply_all') {
        var seen = {}; to.forEach(function (p) { seen[p.email.toLowerCase()] = 1; });
        (sentByUs ? [] : (d.to || [])).forEach(function (p) { if (notOwn(p) && !seen[p.email.toLowerCase()]) { seen[p.email.toLowerCase()] = 1; to.push(p); } });
        (d.cc || []).forEach(function (p) { if (notOwn(p) && !seen[p.email.toLowerCase()]) { seen[p.email.toLowerCase()] = 1; cc.push(p); } });
      }
    }
    knownAddresses();

    var root = el('div', 'ec-cmp'); root.setAttribute('data-kt-btn-free', ''); root.setAttribute('data-kt-nofit', ''); root.setAttribute('data-kt-nospin', '');
    // From
    var fromRow = el('div', 'ec-frow'); fromRow.appendChild(el('span', 'ec-flbl', 'From'));
    var fromSel = el('select', 'ec-from-sel');
    accts.forEach(function (a) { var o = document.createElement('option'); o.value = a.id; o.textContent = (a.display_name ? a.display_name + ' <' + a.email_address + '>' : a.email_address); if (a.id === from.id) o.selected = true; fromSel.appendChild(o); });
    if (accts.length < 2) fromSel.disabled = true;
    fromRow.appendChild(fromSel); root.appendChild(fromRow);
    // To / Cc / Bcc
    var toRow = el('div', 'ec-frow'); toRow.appendChild(el('span', 'ec-flbl', 'To'));
    var toF = chipField(to, sync); toRow.appendChild(toF.el);
    var ccb = el('div', 'ec-ccbtns'); var ccBtn = el('button', '', 'Cc'); ccBtn.type = 'button'; var bccBtn = el('button', '', 'Bcc'); bccBtn.type = 'button';
    ccb.appendChild(ccBtn); ccb.appendChild(bccBtn); toRow.appendChild(ccb); root.appendChild(toRow);
    var ccRow = el('div', 'ec-frow'); ccRow.appendChild(el('span', 'ec-flbl', 'Cc')); var ccF = chipField(cc, sync); ccRow.appendChild(ccF.el); root.appendChild(ccRow);
    var bccRow = el('div', 'ec-frow'); bccRow.appendChild(el('span', 'ec-flbl', 'Bcc')); var bccF = chipField([], sync); bccRow.appendChild(bccF.el); root.appendChild(bccRow);
    ccRow.style.display = cc.length ? '' : 'none'; bccRow.style.display = 'none';
    ccBtn.style.display = cc.length ? 'none' : '';
    ccBtn.addEventListener('click', function () { ccRow.style.display = ''; ccBtn.style.display = 'none'; ccF.focus(); });
    bccBtn.addEventListener('click', function () { bccRow.style.display = ''; bccBtn.style.display = 'none'; bccF.focus(); });
    // Subject
    var sRow = el('div', 'ec-frow'); sRow.appendChild(el('span', 'ec-flbl', 'Subject'));
    var subj = el('input', 'ec-plain'); subj.type = 'text'; subj.placeholder = 'Add a subject';
    subj.value = m ? prefix(mode, d.subject || m.subject) : '';
    sRow.appendChild(subj); root.appendChild(sRow);
    // Formatting bar (+ attach, + preview)
    var fmt = el('div', 'ec-fmt');
    var CMDS = [['bold', '<b>B</b>', 'Bold (Ctrl+B)'], ['italic', '<i>I</i>', 'Italic (Ctrl+I)'], ['underline', '<u>U</u>', 'Underline (Ctrl+U)'], ['insertUnorderedList', '•', 'Bulleted list'], ['insertOrderedList', '1.', 'Numbered list'], ['link', '🔗', 'Insert link'], ['removeFormat', 'T̸', 'Clear formatting']];
    CMDS.forEach(function (c) {
      var b = el('button', '', c[1]); b.type = 'button'; b.title = c[2]; b.setAttribute('data-kt-iconized', '1');
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        ed.focus();
        if (c[0] === 'link') { var u = window.prompt('Link address (https://…)', 'https://'); if (u && /^(https?:|mailto:)/i.test(u)) document.execCommand('createLink', false, u); return; }
        document.execCommand(c[0], false, null); sync();
      });
      fmt.appendChild(b);
    });
    fmt.appendChild(el('span', 'sp'));
    // ✨ AI (2026-11-09): suggest replies / rewrite the draft. Sales mailboxes only (the endpoint is in that group).
    var aiBtn = salesMode() ? el('button', 'ec-ai-b', '✨ AI') : null;
    if (aiBtn) { aiBtn.type = 'button'; aiBtn.setAttribute('data-kttip', 'Suggest replies or rewrite what you wrote'); fmt.appendChild(aiBtn); }
    var attachBtn = el('button', '', '📎 Attach'); attachBtn.type = 'button'; attachBtn.title = 'Attach files (or drag them here)'; attachBtn.setAttribute('data-kt-iconized', '1');
    var prevBtn = el('button', '', '👁 Preview'); prevBtn.type = 'button'; prevBtn.title = 'See exactly what will be sent'; prevBtn.setAttribute('data-kt-iconized', '1');
    fmt.appendChild(attachBtn); fmt.appendChild(prevBtn);
    root.appendChild(fmt);
    var aiPanel = el('div', 'ec-ai'); aiPanel.style.display = 'none'; root.appendChild(aiPanel);
    var picker = el('input'); picker.type = 'file'; picker.multiple = true; picker.style.display = 'none'; root.appendChild(picker);
    // Editor: our part + the signature, as it will look
    var ed = el('div', 'ec-editor ec-scroll'); ed.contentEditable = 'true'; ed.setAttribute('data-kt-scroll', ''); ed.setAttribute('role', 'textbox'); ed.setAttribute('aria-multiline', 'true');
    ed.setAttribute('data-ph', 'Write your message…');
    var sig = sigHtml(from);
    ed.innerHTML = '<div><br></div>' + (sig ? '<div class="ec-sig" data-ec-sig="1">' + sig + '</div>' : '');
    root.appendChild(ed);
    var preview = el('div', 'ec-preview'); preview.style.display = 'none'; root.appendChild(preview);
    // Files
    var files = el('div', 'ec-files'); root.appendChild(files);
    var picked = [];
    var origAtts = (mode === 'forward' && d && d.attachments) || [];
    // The quoted original (Outlook adds it below; shown here as it will appear)
    var quote = null;
    if (m) {
      var who = function (p) { return p ? (p.name ? p.name + ' <' + p.email + '>' : p.email) : ''; };
      var head = '<hr style="border:none;border-top:1px solid #E1E1E1;margin:4px 0 8px"><div style="font-size:13px;color:#1F2937"><b>From:</b> ' + esc(who(d.from)) + '<br><b>Sent:</b> ' + esc(d.when || m.date)
        + '<br><b>To:</b> ' + esc((d.to || []).map(who).join('; ')) + ((d.cc || []).length ? '<br><b>Cc:</b> ' + esc(d.cc.map(who).join('; ')) : '') + '<br><b>Subject:</b> ' + esc(d.subject || m.subject) + '</div><br>';
      // The quoted original keeps its own background, but not its page-wide <style> blocks: those would restyle the
      // reply written above it in the sent message (2026-11-04).
      var qd = mailDoc(d.html || '');
      quote = { html: head + (qd.bodyStyle ? '<div style="' + qd.bodyStyle + '">' + qd.inner + '</div>' : qd.inner) };
      var q = el('div', 'ec-quote');
      var qh = el('div', 'ec-quote-h', '<span class="tx">' + (mode === 'forward' ? 'The forwarded message' : 'The original message') + ' goes below your ' + (mode === 'forward' ? 'note' : 'reply') + ', as Outlook does.</span>');
      var qt = el('button', 'ec-sbtn', 'Hide'); qt.type = 'button'; qt.style.height = '26px';
      qt.addEventListener('click', function () { q.classList.toggle('closed'); qt.textContent = q.classList.contains('closed') ? 'Show' : 'Hide'; });
      qh.appendChild(qt); q.appendChild(qh); q.appendChild(mailFrame(quote.html, '', false));
      root.appendChild(q);
    }
    // Footer: status, limit, Discard, Send (the ONE send button)
    var foot = el('div', 'ec-cfoot');
    var status = el('div', 'st ok'); var lim = el('span', 'lim');
    function sigNote() {
      if (sigHtml(from)) { if (status.getAttribute('data-sig') === '1') { status.textContent = ''; status.removeAttribute('data-sig'); } return; }
      status.className = 'st ok'; status.setAttribute('data-sig', '1'); status.innerHTML = '';
      var sgt = el('span', 'sgtx'); sgt.textContent = from.email_address + ' has no signature yet. '; status.appendChild(sgt);
      var sl = el('button', 'ec-sbtn', '✍️ Add signature'); sl.type = 'button'; sl.style.height = '24px';
      sl.addEventListener('click', function () { openAccountSettings(from); });
      status.appendChild(sl);
    }
    var discard = el('button', 'ec-sbtn', 'Discard'); discard.type = 'button';
    var send = el('button', 'kt-send-one', '➤'); send.type = 'button'; send.title = 'Send (Ctrl+Enter)'; send.setAttribute('aria-label', 'Send');
    foot.appendChild(status); foot.appendChild(lim); foot.appendChild(discard); foot.appendChild(send);
    root.appendChild(foot);

    /* AI IN THE COMPOSER (2026-11-09, Anthony: "add AI to the email client to rewrite or suggest replies to emails").
       POST /sales/email/ai only ever suggests: the text lands in the box for the person to read, change and send;
       one Undo puts back what was there. The signature and the quoted original are never sent to it or changed. */
    function ourText() {
      var c = ed.cloneNode(true); var sg = c.querySelector('[data-ec-sig]'); if (sg) sg.remove();
      return String(c.innerText || c.textContent || '').replace(/\u00a0/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
    }
    function origText() {
      if (!d || !d.html) return '';
      try { var doc = new DOMParser().parseFromString(d.html, 'text/html'); [].forEach.call(doc.querySelectorAll('style,script,head'), function (x) { x.remove(); });
        return String(doc.body ? (doc.body.innerText || doc.body.textContent || '') : '').replace(/[ \t]+/g, ' ').replace(/\n\s*\n\s*/g, '\n\n').trim().slice(0, 12000);
      } catch (e) { return ''; }
    }
    var aiUndo = null;
    function putText(t) {
      aiUndo = ed.innerHTML;
      var sg = ed.querySelector('[data-ec-sig]'); var keep = sg ? sg.outerHTML : '';
      var html = String(t).split(/\n{2,}/).map(function (para) { return '<div>' + esc(para.trim()).replace(/\n/g, '<br>') + '</div><div><br></div>'; }).join('');
      ed.innerHTML = html + keep;
      sync();
      status.className = 'st ok'; status.removeAttribute('data-sig'); status.innerHTML = '';
      status.appendChild(document.createTextNode('✨ AI suggestion in place - read it before sending. '));
      var u = el('button', 'ec-sbtn', '↩ Undo'); u.type = 'button'; u.style.height = '24px';
      u.addEventListener('click', function () { if (aiUndo != null) { ed.innerHTML = aiUndo; aiUndo = null; sync(); } status.textContent = ''; sigNote(); });
      status.appendChild(u);
    }
    function aiCall(body, busy) {
      if (aiBtn) { aiBtn.disabled = true; aiBtn.textContent = '✨ ' + busy; }
      body.sender = (from && (from.display_name || from.email_address)) || null;
      if (d) body.original = { from: d.from ? ((d.from.name ? d.from.name + ' ' : '') + '<' + d.from.email + '>') : '', subject: d.subject || (m && m.subject) || '', text: origText() };
      return api().post(BASE + '/ai', body).finally(function () { if (aiBtn) { aiBtn.disabled = false; aiBtn.textContent = '✨ AI'; } });
    }
    function aiFail(e) { status.className = 'st'; status.textContent = (e && e.message) || 'The AI did not answer just now. Please try again.'; }
    function aiSuggest() {
      aiPanel.style.display = ''; aiPanel.innerHTML = '<div class="ec-ai-h">💡 Suggested replies</div><div class="ec-ai-w">Reading the email…</div>';
      aiCall({ mode: 'suggest', draft: ourText() || null }, 'Thinking…').then(function (r) {
        aiPanel.innerHTML = '';
        var hd = el('div', 'ec-ai-h', '💡 Suggested replies <span>pick one to put it in your reply; you can still change it</span>');
        var x = el('button', 'ec-ai-x', '✕'); x.type = 'button'; x.setAttribute('aria-label', 'Close suggestions'); x.addEventListener('click', function () { aiPanel.style.display = 'none'; });
        hd.appendChild(x); aiPanel.appendChild(hd);
        var row = el('div', 'ec-ai-row');
        ((r && r.suggestions) || []).forEach(function (sg) {
          var card = el('button', 'ec-ai-card'); card.type = 'button';
          card.innerHTML = '<b>' + esc(sg.label) + '</b><span>' + esc(sg.text) + '</span><i>Use this</i>';
          card.addEventListener('click', function () { putText(sg.text); aiPanel.style.display = 'none'; ed.focus(); });
          row.appendChild(card);
        });
        aiPanel.appendChild(row);
      }).catch(function (e) { aiPanel.style.display = 'none'; aiFail(e); });
    }
    function aiRewrite(style, label) {
      var t = ourText();
      if (t.length < 3) { aiFail({ message: 'Write something first, then ask the AI to ' + label.toLowerCase() + '.' }); ed.focus(); return; }
      aiCall({ mode: 'rewrite', style: style, draft: t }, 'Rewriting…').then(function (r) { if (r && r.text) putText(r.text); }).catch(aiFail);
    }
    if (aiBtn) aiBtn.addEventListener('click', function () {
      var item = function (label, fn) { var x = document.createElement('button'); x.type = 'button'; x.textContent = label; x.addEventListener('click', fn); return x; };
      var acts = [];
      if (m && (mode === 'reply' || mode === 'reply_all')) acts.push(item('💡 Suggest replies', aiSuggest));
      acts.push(item('📝 Turn my notes into an email', function () { aiRewrite('expand', 'Turn notes into an email'); }));
      acts.push(item('✍️ Make it clearer', function () { aiRewrite('clearer', 'Make it clearer'); }));
      acts.push(item('✂️ Make it shorter', function () { aiRewrite('shorter', 'Make it shorter'); }));
      acts.push(item('😊 Make it friendlier', function () { aiRewrite('friendlier', 'Make it friendlier'); }));
      acts.push(item('👔 Make it more formal', function () { aiRewrite('formal', 'Make it more formal'); }));
      acts.push(item('🔤 Fix spelling & grammar', function () { aiRewrite('fix', 'Fix spelling'); }));
      if (KT.openActionMenu) KT.openActionMenu(aiBtn, acts);
    });

    function drawFiles() {
      files.innerHTML = '';
      origAtts.forEach(function (x) { files.appendChild(el('span', 'ec-file orig', fileIcon(x.type || x.name) + '<span class="nm">' + esc(x.name) + '</span><span class="sz">' + fmtBytes(x.size) + '</span>')).title = 'From the original message — included with the forward'; });
      picked.forEach(function (f, i) {
        var c = el('span', 'ec-file', fileIcon(f.type || f.name) + '<span class="nm">' + esc(f.name) + '</span><span class="sz">' + fmtBytes(f.size) + '</span>');
        var rm = el('button', '', '✕'); rm.type = 'button'; rm.title = 'Remove ' + f.name; rm.setAttribute('aria-label', 'Remove ' + f.name);
        rm.addEventListener('click', function () { picked.splice(i, 1); drawFiles(); sync(); });
        c.appendChild(rm); files.appendChild(c);
      });
      files.style.display = (origAtts.length || picked.length) ? '' : 'none';
      var tot = picked.reduce(function (s, f) { return s + f.size; }, 0);
      lim.textContent = picked.length ? fmtBytes(tot) + ' of 25 MB' : '';
    }
    function addFiles(list) {
      var tot = picked.reduce(function (s, f) { return s + f.size; }, 0), skipped = [];
      [].forEach.call(list || [], function (f) {
        if (picked.length >= MAX_FILES) { skipped.push(f.name + ' (10 files at most)'); return; }
        if (tot + f.size > MAX_TOTAL) { skipped.push(f.name + ' (over 25 MB in total)'); return; }
        picked.push(f); tot += f.size;
      });
      drawFiles(); sync();
      if (skipped.length) { status.className = 'st'; status.textContent = 'Not attached: ' + skipped.join(', ') + '.'; }
    }
    attachBtn.addEventListener('click', function () { picker.click(); });
    picker.addEventListener('change', function () { addFiles(picker.files); picker.value = ''; });
    var dragDepth = 0;
    root.addEventListener('dragenter', function (e) { if (e.dataTransfer && [].indexOf.call(e.dataTransfer.types || [], 'Files') > -1) { e.preventDefault(); dragDepth++; root.classList.add('drag'); } });
    root.addEventListener('dragover', function (e) { if (root.classList.contains('drag')) e.preventDefault(); });
    root.addEventListener('dragleave', function () { if (--dragDepth <= 0) { dragDepth = 0; root.classList.remove('drag'); } });
    root.addEventListener('drop', function (e) { if (!e.dataTransfer || !e.dataTransfer.files.length) return; e.preventDefault(); dragDepth = 0; root.classList.remove('drag'); addFiles(e.dataTransfer.files); });

    // From change: the new mailbox's signature replaces the old one, where the person left it
    fromSel.addEventListener('change', function () {
      from = acctById(fromSel.value) || from;
      var s = sigHtml(from), cur = ed.querySelector('[data-ec-sig]');
      if (cur && s) cur.innerHTML = s; else if (cur) cur.remove(); else if (s) ed.insertAdjacentHTML('beforeend', '<div class="ec-sig" data-ec-sig="1">' + s + '</div>');
      sigNote();
    });
    function written() { return (ed.innerText || '').replace(/\s+/g, ' ').trim() !== '' || ed.querySelector('img'); }
    function sync() {
      var n = toF.count() + ccF.count() + bccF.count();
      var ok = n > 0 || mode === 'reply' || mode === 'reply_all';
      send.disabled = !ok || (!written() && !picked.length && mode !== 'forward');
    }
    ed.addEventListener('input', sync);
    ed.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); if (!send.disabled) send.click(); } });
    function bodyHtml() {
      var c = ed.cloneNode(true);
      [].forEach.call(c.querySelectorAll('[data-ec-sig]'), function (s) { s.removeAttribute('data-ec-sig'); s.removeAttribute('class'); s.style.marginTop = '14px'; });
      return c.innerHTML;
    }
    prevBtn.addEventListener('click', function () {
      var on = preview.style.display === 'none';
      if (on) {
        preview.innerHTML = '';
        var whole = '<div style="font-family:Segoe UI,Calibri,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1F2937;">' + bodyHtml() + '</div>' + (quote ? quote.html : '');
        preview.appendChild(mailFrame(whole, '', false));
      }
      preview.style.display = on ? '' : 'none'; ed.style.display = on ? 'none' : '';
      prevBtn.classList.toggle('on', on); prevBtn.textContent = on ? '✏️ Edit' : '👁 Preview';
    });
    drawFiles(); sync(); sigNote();

    var sending = false;
    discard.addEventListener('click', function () {
      if (written() && discard.dataset.armed !== '1') { discard.dataset.armed = '1'; discard.textContent = 'Discard this message?'; return; }
      Shell.Modal.close();
    });
    send.addEventListener('click', function () {
      if (sending) return;
      var toL = toF.emails(), ccL = ccF.emails(), bccL = bccF.emails();
      var bad = toF.bad().concat(ccF.bad(), bccF.bad());
      status.className = 'st';
      if (bad.length) { status.textContent = 'Check ' + bad.join(', ') + ' — not a valid address.'; return; }
      if (!toL.length && !ccL.length && !bccL.length && (mode === 'new' || mode === 'forward')) { status.textContent = 'Add who it goes to.'; toF.focus(); return; }
      if (mode === 'new' && !subj.value.trim()) { status.textContent = 'Add a subject.'; subj.focus(); return; }
      var fd = new FormData();
      fd.append('account_id', from.id); fd.append('mode', mode);
      if (m) { fd.append('mid', m.uid); fd.append('source_account_id', src.id); }
      toL.forEach(function (x) { fd.append('to[]', x); }); ccL.forEach(function (x) { fd.append('cc[]', x); }); bccL.forEach(function (x) { fd.append('bcc[]', x); });
      fd.append('subject', subj.value.trim());
      fd.append('html', bodyHtml());
      picked.forEach(function (f) { fd.append('files[]', f, f.name); });
      sending = true; send.disabled = true; send.setAttribute('aria-busy', 'true');
      status.className = 'st ok'; status.textContent = picked.length ? 'Uploading and sending…' : 'Sending…';
      api().postForm(BASE + '/send', fd).then(function () {
        sending = false;
        Shell.Modal.close();
        toast('Sent from ' + from.email_address + ' ✓', 'success');
        if (STATE.acct && STATE.acct.id === from.id && isSentLike(from, STATE.folder)) setTimeout(function () { loadList(false, true); }, 1500);
      }).catch(function (e) {
        sending = false; send.removeAttribute('aria-busy'); sync();
        status.className = 'st'; status.textContent = (e && e.message) || 'It could not be sent. Please try again.';
      });
    });

    var title = { reply: '↩️ Reply', reply_all: '⤶ Reply all', forward: '➡️ Forward', 'new': '✏️ New message' }[mode];
    Shell.Modal.open({ title: title + (m ? ' — ' + (d.subject || m.subject || '') : ''), body: root, large: true, onClose: function () { sending = false; } });
    setTimeout(function () {
      try {
        if (mode === 'new' || mode === 'forward') toF.focus();
        else { ed.focus(); var r = document.createRange(); r.setStart(ed.firstChild, 0); r.collapse(true); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
      } catch (e) {}
    }, 80);
  }

  // ══════════════════════════════════════════════════════════════════
  //  ACCOUNTS: manager, add wizard, settings (signature, out of office)
  // ══════════════════════════════════════════════════════════════════
  var PROVIDERS = [
    { key: 'microsoft', label: 'Microsoft 365 / Outlook', icon: '🟦', hint: 'Sign in with Microsoft — sales@, info@, a shared mailbox, outlook.com' },
    { key: 'google', label: 'Google Workspace / Gmail', icon: '🔴', hint: 'Sign in with Google — gmail.com or a Google-hosted domain' },
    { key: 'imap', label: 'IMAP / SMTP', icon: '✉️', hint: 'Any other provider — enter server settings (read only)' }
  ];
  /* prov 'google' opens Google's sign-in page instead (2026-11-04); the way back is the same (?mbx=, "g" nonces). */
  function startMicrosoft(id, btn, statusEl, prov) {
    var who = prov === 'google' ? 'Google' : 'Microsoft';
    var label = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Opening ' + who + '…'; }
    api().post(BASE + '/accounts/' + id + (prov === 'google' ? '/google' : '/microsoft'), {}).then(function (r) {
      if (r && r.url) { window.location.href = r.url; return; }
      throw new Error(who + ' sign-in could not be started.');
    }).catch(function (e) {
      if (btn) { btn.disabled = false; btn.innerHTML = label; }
      var msg = (e && e.message) || (who + ' sign-in could not be started.');
      if (statusEl) statusEl.textContent = msg; else toast(msg, 'info');
    });
  }
  function takeMicrosoftReturn() {
    var q;
    try { q = new URLSearchParams(window.location.search); } catch (e) { return null; }
    var nonce = q.get('mbx'), err = q.get('mbx_error');
    if (!nonce && !err) return null;
    q.delete('mbx'); q.delete('mbx_error');
    var rest = q.toString();
    try { history.replaceState(history.state, '', window.location.pathname + (rest ? '?' + rest : '') + window.location.hash); } catch (e) {}
    return { nonce: nonce, error: err };
  }
  function showMicrosoftProblem(msg) {
    var body = el('div');
    var p = el('div'); p.style.cssText = 'font-size:13.5px;color:#334155;line-height:1.55;margin-bottom:14px;'; p.textContent = msg;
    var b = el('button', 'ec-pbtn', '✉️ Manage accounts'); b.type = 'button';
    b.addEventListener('click', function () { openAccounts(); });
    body.appendChild(p); body.appendChild(b);
    Shell.Modal.open({ title: '✉️ Mailbox sign-in', body: body });
  }
  function providerLabel(k) { var p = PROVIDERS.filter(function (x) { return x.key === k; })[0]; return p ? p.label : k; }
  function fld(label, e) {
    var w = el('div'); w.style.cssText = 'margin-bottom:10px;';
    var l = el('label'); l.style.cssText = 'display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:4px;'; l.textContent = label;
    w.appendChild(l); w.appendChild(e); return w;
  }
  function inp(val, ph, type) {
    var i = el('input'); i.type = type || 'text'; if (ph) i.placeholder = ph; if (val != null) i.value = val;
    i.style.cssText = 'width:100%;height:32px;padding:0 10px;border:1px solid #D5DCE4;border-radius:8px;font-size:13px;box-sizing:border-box;font-family:inherit;';
    return i;
  }
  function statusBadge(a) {
    var k = a.needs_reconnect ? 'reconnect' : a.status;
    var map = { connected: ['#DCFCE7', '#166534', '● Connected'], error: ['#FEE2E2', '#B91C1C', '● Error'], pending: ['#FEF3C7', '#92400E', '● Not tested'], reconnect: ['#FFEDD5', '#9A3412', '● Needs sign-in'] };
    var c = map[k] || map.pending;
    return '<span style="background:' + c[0] + ';color:' + c[1] + ';border-radius:20px;padding:2px 9px;font-size:11px;font-weight:800;white-space:nowrap;">' + c[2] + '</span>';
  }
  function reloadAfterAccounts() { if (R && R.root.isConnected) { loadAccounts().then(function (accts) { drawBanners(); if (!accts.length) { noAccounts(); return; } if (!STATE.acct || !acctById(STATE.acct.id)) { STATE.acct = accts[0]; STATE.folder = 'INBOX'; STATE.autoPick = true; STATE.hl = null; } drawTree(); Promise.all(accts.map(loadFolders)).then(drawTree); loadList(false); }); } }

  function openAccounts() {
    var body = el('div', 'ec-cmp', '<div style="color:#64748B;font-size:13px;padding:8px;">Loading accounts…</div>'); body.setAttribute('data-kt-btn-free', ''); body.setAttribute('data-kt-nospin', '');
    Shell.Modal.open({ title: '✉️ Email accounts', body: body, large: true });
    api().get(BASE + '/accounts').then(function (r) {
      var accts = (r && r.accounts) || [];
      body.innerHTML = '';
      var intro = el('div'); intro.style.cssText = 'font-size:12.5px;color:#64748B;margin:2px 0 12px;line-height:1.5;';
      intro.textContent = salesMode()
        ? 'The mailboxes the sales team works from (sales@, info@ and any others). Each has its own signature and out-of-office reply; new mail in any of them rings the bell.'
        : 'Connect one or more mailboxes to read them inside KiddieTrac. Each account has its own signature and out-of-office reply.';
      body.appendChild(intro);
      if (!accts.length) body.appendChild(el('div', '', '<div style="text-align:center;color:#64748B;font-size:13px;padding:22px;border:1.5px dashed #CBD5E1;border-radius:12px;margin-bottom:12px;"><div style="font-size:30px;margin-bottom:6px;">📭</div>No accounts connected yet.</div>'));
      accts.forEach(function (a) { body.appendChild(accountCard(a)); });
      var add = el('button', 'ec-pbtn', '＋ Add account'); add.type = 'button'; add.style.marginTop = '4px';
      add.addEventListener('click', function () { openWizard(); });
      body.appendChild(add);
    }).catch(function () { body.innerHTML = '<div style="color:#B91C1C;padding:10px;">Could not load accounts.</div>'; });
  }
  function accountCard(a) {
    wizardCss();
    var card = el('div'); card.style.cssText = 'border:1px solid #E5E7EB;border-radius:12px;padding:11px 13px;margin-bottom:9px;display:flex;align-items:center;gap:12px;';
    var detail = (a.status === 'error' && a.status_detail)
      ? '<div style="font-size:11.5px;color:#B91C1C;margin-top:5px;line-height:1.45;">' + esc(a.status_detail) + '</div>'
      : ((a.auth_type === 'microsoft' || a.auth_type === 'google') && a.oauth_identity ? '<div style="font-size:11.5px;color:#64748B;margin-top:4px;">Signed in with ' + (a.auth_type === 'google' ? 'Google' : 'Microsoft') + ' as ' + esc(a.oauth_identity) + '</div>' : '');
    card.innerHTML = '<span class="ec-av" data-kt-emoji="off" style="width:36px;height:36px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;background:' + avColor(a.email_address) + ';">' + esc(initials(a.display_name || a.email_address)) + '</span>'
      + '<div style="flex:1;min-width:0;"><div style="font-weight:800;color:#0D1B2A;font-size:14px;">' + esc(a.display_name || a.email_address) + (a.is_default ? ' <span title="Default" style="color:#F59E0B;">★</span>' : '') + '</div>'
      + '<div style="font-size:12px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:5px;">' + logo(BRAND[a.provider] ? a.provider : 'imap', 14) + '<span style="overflow:hidden;text-overflow:ellipsis;">' + esc(a.email_address) + ' · ' + esc(providerLabel(a.provider)) + (a.can_send ? ' · sends from here' : (a.send_needs_reconnect ? ' · reconnect once to send' : '')) + '</span></div>'
      + '<div style="margin-top:5px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">' + statusBadge(a)
      + (a.ooo_enabled ? '<span style="background:#EDE9FE;color:#6D28D9;border-radius:20px;padding:2px 9px;font-size:11px;font-weight:800;">🌴 Out of office</span>' : '') + '</div>' + detail + '</div>';
    var actions = el('div'); actions.style.cssText = 'display:flex;flex-direction:column;gap:5px;flex-shrink:0;';
    if (a.provider === 'microsoft' || (a.provider === 'google' && a.google_signin)) {
      // Connected before sending existed (2026-11-09): Reconnect is the main button, so one sign-in adds sending.
      var msBroken = a.auth_type !== a.provider || a.status === 'error' || a.needs_reconnect || a.send_needs_reconnect;
      var msB = el('button', msBroken ? 'ec-pbtn' : 'ec-sbtn', a.auth_type === a.provider ? '🔌 Reconnect' : logo(a.provider === 'google' ? 'google' : 'microsoft', 15) + ' Sign in'); msB.type = 'button';
      if (!msBroken) msB.title = a.provider === 'google' ? 'Sign in with Google again' : 'Sign in with Microsoft again, for example to allow reading automatic replies';
      else if (a.send_needs_reconnect && !a.needs_reconnect) msB.title = 'Sign in with Microsoft once more to allow sending from this mailbox';
      msB.addEventListener('click', function () { startMicrosoft(a.id, msB, null, a.provider); });
      actions.appendChild(msB);
    }
    var testB = el('button', 'ec-sbtn', 'Test'); testB.type = 'button';
    testB.addEventListener('click', function () {
      testB.textContent = 'Testing…'; testB.disabled = true;
      api().post(BASE + '/accounts/' + a.id + '/test', {}).then(function (res) {
        toast(res && res.ok ? 'Connected ✓' : 'Not connected — see the account for why.', res && res.ok ? 'success' : 'info');
        openAccounts(); reloadAfterAccounts();
      }).catch(function () { testB.textContent = 'Test'; testB.disabled = false; toast('Test failed.', 'info'); });
    });
    var editB = el('button', 'ec-sbtn', 'Settings'); editB.type = 'button'; editB.setAttribute('data-kt-iconized', '1');
    editB.addEventListener('click', function () { openAccountSettings(a); });
    actions.appendChild(testB); actions.appendChild(editB);
    card.appendChild(actions);
    return card;
  }

  /* ADD AN ACCOUNT THE WAY OUTLOOK DOES (2026-11-04, Anthony: "the mail client should be able to add mailboxes like how
     outlook does it without any secret keys", then "needs to look better, use real branded icons"). The address comes
     first; /email/discover works out who handles it (Microsoft 365, Google, or another provider's server settings) and
     the next step is that provider's own sign-in page - no app passwords, no server names. The provider tiles below
     the address skip the lookup. Logos are the providers' own marks, drawn inline (no network, no tracking). */
  var BRAND = {
    microsoft: '<svg viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#F25022"/><rect x="11" y="1" width="9" height="9" fill="#7FBA00"/><rect x="1" y="11" width="9" height="9" fill="#00A4EF"/><rect x="11" y="11" width="9" height="9" fill="#FFB900"/></svg>',
    google: '<svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>',
    yahoo: '<svg viewBox="0 0 48 48" aria-hidden="true"><rect width="48" height="48" rx="11" fill="#6001D2"/><text x="24" y="32" text-anchor="middle" font-family="Arial Black,Arial,sans-serif" font-weight="900" font-size="21" fill="#fff">Y!</text></svg>',
    icloud: '<svg viewBox="0 0 48 48" aria-hidden="true"><rect width="48" height="48" rx="11" fill="#3693F3"/><path fill="#fff" d="M35.5 33H14.2a7.2 7.2 0 0 1-1-14.3 9.6 9.6 0 0 1 17.9-3.1 6 6 0 0 1 8.3 5.6v.4A5.8 5.8 0 0 1 35.5 33z"/></svg>',
    imap: '<svg viewBox="0 0 48 48" aria-hidden="true"><rect width="48" height="48" rx="11" fill="#E2E8F0"/><path fill="none" stroke="#475569" stroke-width="3" stroke-linejoin="round" d="M11 15h26v18H11z"/><path fill="none" stroke="#475569" stroke-width="3" stroke-linejoin="round" d="M11 16l13 10 13-10"/></svg>'
  };
  // Mail services that need an app password over IMAP; their server settings are known.
  var PRESET = {
    yahoo: { label: 'Yahoo Mail', host: 'imap.mail.yahoo.com', port: 993, encryption: 'ssl', pw: 'Yahoo needs an app password: Yahoo account → Account security → Generate app password.' },
    icloud: { label: 'iCloud Mail', host: 'imap.mail.me.com', port: 993, encryption: 'ssl', pw: 'iCloud needs an app-specific password: appleid.apple.com → Sign-In and Security → App-Specific Passwords.' }
  };
  function logo(key, px) { return '<span class="ec-wz-logo" style="width:' + px + 'px;height:' + px + 'px;">' + (BRAND[key] || BRAND.imap) + '</span>'; }
  function wizardCss() {
    if (document.getElementById('kt-email-wizard-css')) return;
    var s = document.createElement('style'); s.id = 'kt-email-wizard-css';
    s.textContent = [
      '.ec-wz{display:flex;flex-direction:column;gap:12px;max-width:560px;margin:0 auto;padding:4px 2px 6px;}',
      '.ec-wz-logo{display:inline-flex;flex:0 0 auto;}.ec-wz-logo svg{width:100%;height:100%;display:block;}',
      '.ec-wz-hero{display:flex;align-items:center;gap:14px;}',
      '.ec-wz-hero h4{margin:0;font-size:17px;font-weight:800;color:#0F172A;line-height:1.3;}',
      '.ec-wz-hero p{margin:3px 0 0;font-size:13px;color:#64748B;line-height:1.45;}',
      '.ec-wz-marks{display:flex;}.ec-wz-marks .ec-wz-logo{margin-right:-6px;border-radius:9px;background:#fff;box-shadow:0 0 0 2px #fff,0 1px 4px rgba(15,23,42,.15);padding:5px;box-sizing:border-box;}',
      '.ec-wz-in{display:flex;gap:8px;}',
      '.ec-wz-in input{flex:1;min-width:0;height:40px!important;font-size:14.5px!important;border-radius:10px!important;padding:0 12px!important;}',
      '.ec-wz .ec-pbtn,.ec-wz .ec-sbtn{height:40px;padding:0 18px;border-radius:10px;font-size:13.5px;width:auto;}',
      '.ec-wz-or{background:none!important;border:0!important;padding:0!important;display:flex;align-items:center;gap:10px;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#94A3B8;margin-top:4px;}',
      '.ec-wz-or::before,.ec-wz-or::after{content:"";flex:1;height:1px;background:#E2E8F0;}',
      '.ec-wz-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;}',
      '.ec-wz-tile{display:flex;align-items:center;gap:10px;border:1.5px solid #E2E8F0;border-radius:12px;padding:10px 12px;background:#fff;cursor:pointer;font-family:inherit;text-align:left;transition:border-color .12s,background .12s,box-shadow .12s;}',
      '.ec-wz-tile:hover,.ec-wz-tile:focus-visible{border-color:#0E7C90;background:#F0FAFC;box-shadow:0 2px 8px rgba(14,124,144,.12);outline:none;}',
      '.ec-wz-tile b{display:block;font-size:13px;color:#0F172A;font-weight:800;}.ec-wz-tile small{display:block;font-size:11.5px;color:#64748B;margin-top:1px;}',
      '.ec-wz-note{font-size:12.5px;color:#475569;background:#F8FAFC;border:1px solid #E9EEF3;border-radius:10px;padding:10px 12px;line-height:1.55;}',
      '.ec-wz-ok{font-size:12px;color:#047857;}.ec-wz-warn{font-size:12px;color:#92400E;}',
      '.ec-wz-err{font-size:12.5px;min-height:16px;color:#B91C1C;line-height:1.45;}',
      '.ec-wz-top{display:flex;align-items:center;gap:10px;}',
      '.ec-wz-chip{display:inline-flex;align-items:center;gap:8px;background:#F1F5F9;border-radius:999px;padding:5px 12px 5px 6px;font-size:13px;font-weight:700;color:#0F172A;min-width:0;}',
      '.ec-wz-chip span.t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ec-wz-back{border:0;background:none;color:#0E7C90;font:700 13px inherit;font-family:inherit;cursor:pointer;padding:6px 4px;}',
      /* Provider sign-in buttons in the providers' own style: white, their mark on the left. */
      '.ec-wz-brandbtn{display:inline-flex;align-items:center;justify-content:center;gap:10px;height:42px;padding:0 18px;border:1px solid #DADCE0;border-radius:10px;background:#fff;color:#1F1F1F;font:600 14px inherit;font-family:inherit;cursor:pointer;box-shadow:0 1px 2px rgba(15,23,42,.08);}',
      '.ec-wz-brandbtn:hover{background:#F8FAFC;box-shadow:0 2px 6px rgba(15,23,42,.12);}.ec-wz-brandbtn:disabled{opacity:.6;cursor:default;}',
      '.ec-wz-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}',
      '.ec-wz-2{display:grid;grid-template-columns:1fr 1fr;gap:0 10px;}.ec-wz-3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:0 10px;}',
      '@media(max-width:560px){.ec-wz-2,.ec-wz-3{grid-template-columns:1fr;}.ec-wz-in{flex-direction:column;}.ec-wz-grid{grid-template-columns:1fr 1fr;}}'
    ].join('');
    document.head.appendChild(s);
  }
  function openWizard() {
    wizardCss();
    // ec-cmp: the mail screen's colours and buttons - a popup sits outside .ec-wrap, where they were undefined (bare buttons).
    var body = el('div', 'ec-cmp'); body.setAttribute('data-kt-btn-free', ''); body.setAttribute('data-kt-nospin', '');
    Shell.Modal.open({ title: '✉️ Add an email account', body: body, large: true });
    var state = { step: -1, provider: null, email: '', imap: null, preset: null };
    draw();
    function top(wz) {
      var t = el('div', 'ec-wz-top');
      var back = el('button', 'ec-wz-back', '‹ Back'); back.type = 'button'; back.setAttribute('data-kt-iconized', '1');
      back.addEventListener('click', function () { state.step = -1; draw(); });
      t.appendChild(back);
      wz.appendChild(t);
    }
    function draw() {
      body.innerHTML = '';
      var wz = el('div', 'ec-wz'); body.appendChild(wz);
      if (state.step === -1) {
        var hero = el('div', 'ec-wz-hero');
        hero.innerHTML = '<span class="ec-wz-marks">' + logo('microsoft', 34) + logo('google', 34) + '</span>'
          + '<div><h4>Add your mailbox</h4><p>Type the address. We work out who runs it and take you to their own sign-in page - no passwords or server settings to type here.</p></div>';
        wz.appendChild(hero);
        var row = el('div', 'ec-wz-in');
        var em0 = inp(state.email, 'you@yourcentre.com', 'email'); em0.autocomplete = 'email'; em0.setAttribute('aria-label', 'Email address');
        var next = el('button', 'ec-pbtn', 'Next →'); next.type = 'button'; next.setAttribute('data-kt-iconized', '1');
        row.appendChild(em0); row.appendChild(next); wz.appendChild(row);
        var st0 = el('div', 'ec-wz-err'); wz.appendChild(st0);
        var go0 = function () {
          var v = em0.value.trim();
          if (!v || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { st0.textContent = 'Enter a full email address, like you@yourcentre.com.'; em0.focus(); return; }
          next.disabled = true; next.textContent = 'Checking…'; st0.textContent = '';
          api().post(BASE + '/discover', { email: v }).then(function (r) {
            state.email = v; state.preset = null;
            state.provider = (r && r.provider) || 'imap';
            if (state.provider !== 'imap' && !(r && r.signin)) state.provider = state.provider === 'google' ? 'google_pw' : 'imap';
            state.imap = (r && r.imap) || null;
            state.step = 1; draw();
          }).catch(function (e) { next.disabled = false; next.textContent = 'Next →'; st0.textContent = (e && e.message) || 'Could not check that address.'; });
        };
        next.addEventListener('click', go0);
        em0.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go0(); } });
        wz.appendChild(el('div', 'ec-wz-or', 'or choose your provider'));
        var grid = el('div', 'ec-wz-grid');
        [
          { k: 'microsoft', b: 'Microsoft 365', s: 'Outlook, Hotmail, work' },
          { k: 'google', b: 'Google', s: 'Gmail, Workspace' },
          { k: 'yahoo', b: 'Yahoo Mail', s: 'App password' },
          { k: 'icloud', b: 'iCloud Mail', s: 'App password' },
          { k: 'imap', b: 'Other', s: 'IMAP server' }
        ].forEach(function (p) {
          var t = el('button', 'ec-wz-tile', logo(p.k, 28) + '<span><b>' + esc(p.b) + '</b><small>' + esc(p.s) + '</small></span>'); t.type = 'button'; t.setAttribute('data-kt-iconized', '1');
          t.addEventListener('click', function () {
            state.email = em0.value.trim(); state.preset = PRESET[p.k] || null;
            state.provider = (p.k === 'yahoo' || p.k === 'icloud') ? 'imap' : p.k;
            state.imap = state.preset ? { host: state.preset.host, port: state.preset.port, encryption: state.preset.encryption, username: state.email, found: true } : null;
            state.step = 1; draw();
          });
          grid.appendChild(t);
        });
        wz.appendChild(grid);
        setTimeout(function () { try { em0.focus(); } catch (e) {} }, 60);
        return;
      }
      top(wz);
      if (state.provider === 'microsoft') { drawMicrosoftInto(wz, state.email); return; }
      if (state.provider === 'google') { drawGoogleInto(wz, state.email, function () { state.provider = 'google_pw'; draw(); }); return; }
      drawPassword(wz, state.provider === 'imap', state.email, state.imap, state.preset);
    }
  }
  /* One provider screen: its mark and name on top, the address as a chip once it is known. */
  function wizHead(body, key, title, sub) {
    var h = el('div', 'ec-wz-hero');
    h.innerHTML = logo(key, 40) + '<div><h4>' + esc(title) + '</h4>' + (sub ? '<p>' + esc(sub) + '</p>' : '') + '</div>';
    body.appendChild(h);
  }
  /* Sign-in with Google or Microsoft: the same screen, the provider's own button. */
  function drawSignIn(body, prov, preEmail, opts) {
    var isG = prov === 'google';
    wizHead(body, prov, isG ? 'Sign in with Google' : 'Sign in with Microsoft',
      isG ? 'Gmail and Google Workspace mailboxes.' : 'Microsoft 365, Outlook.com and Hotmail mailboxes, including shared ones.');
    var two = el('div', 'ec-wz-2');
    var name = inp('', opts.namePh);
    var email = inp(preEmail || '', opts.emailPh, 'email');
    two.appendChild(fld(isG ? 'Gmail address *' : 'Mailbox email address *', email));
    two.appendChild(fld('Display name', name));
    body.appendChild(two);
    var how = el('div', 'ec-wz-note'); how.innerHTML = opts.how;
    body.appendChild(how);
    var status = el('div', 'ec-wz-err');
    var row = el('div', 'ec-wz-row');
    var label = logo(prov, 18) + '<span>' + (isG ? 'Sign in with Google' : 'Sign in with Microsoft') + '</span>';
    var go = el('button', 'ec-wz-brandbtn', label); go.type = 'button'; go.setAttribute('data-kt-iconized', '1');
    var createdId = null;   // a second try re-uses the account, never adds a duplicate
    go.addEventListener('click', function () {
      if (createdId) { status.textContent = ''; startMicrosoft(createdId, go, status, prov); return; }
      var em = email.value.trim();
      if (!em || em.indexOf('@') < 1) { status.textContent = 'A valid email address is required.'; email.focus(); return; }
      go.disabled = true; go.textContent = 'Opening ' + (isG ? 'Google' : 'Microsoft') + '…'; status.textContent = '';
      api().post(BASE + '/accounts', { email_address: em, provider: prov, display_name: name.value.trim() || null, username: em }).then(function (r) {
        var id = r && r.account && r.account.id;
        if (!id) throw new Error('The account could not be added.');
        createdId = id; go.disabled = false; go.innerHTML = label;
        startMicrosoft(id, go, status, prov);
      }).catch(function (e) { go.disabled = false; go.innerHTML = label; status.textContent = (e && e.message) || 'Could not add it.'; });
    });
    row.appendChild(go);
    if (opts.alt) {
      var alt = el('button', 'ec-wz-back', opts.alt.label); alt.type = 'button'; alt.setAttribute('data-kt-iconized', '1');
      alt.addEventListener('click', opts.alt.fn);
      row.appendChild(alt);
    }
    body.appendChild(row);
    body.appendChild(status);
    if (!preEmail) setTimeout(function () { try { email.focus(); } catch (e) {} }, 60);
  }
  function drawGoogleInto(body, preEmail, usePassword) {
    drawSignIn(body, 'google', preEmail, {
      namePh: 'e.g. Front Office (optional)', emailPh: 'you@gmail.com',
      how: 'You’ll sign in on <strong>Google’s own page</strong> and allow KiddieTrac to read this mailbox. KiddieTrac never sees the password and stays signed in from then on. Sign in as this address itself.',
      alt: usePassword ? { label: 'Use an app password instead', fn: usePassword } : null
    });
  }
  function drawMicrosoftInto(body, preEmail) {
    drawSignIn(body, 'microsoft', preEmail, {
      namePh: 'e.g. KiddieTrac Info (optional)', emailPh: salesMode() ? 'info@kiddietrac.com' : 'office@yourcentre.com',
      how: 'You’ll sign in on <strong>Microsoft’s own page</strong>; KiddieTrac never sees the password and stays signed in from then on.'
        + '<br><br><strong>Its own sign-in</strong> (like sales@): sign in as that address. <strong>A shared mailbox</strong> (no password of its own): sign in as <strong>yourself</strong> - you need Full Access to it in Microsoft 365.'
    });
  }
  function drawPassword(body, manual, preEmail, found, preset) {
    if (preset) wizHead(body, preset === PRESET.yahoo ? 'yahoo' : 'icloud', preset.label, preset.pw);
    else if (manual) wizHead(body, 'imap', 'Other mail provider', found && found.found ? 'We found this provider’s server settings - only the password is left.' : 'Enter the mailbox password. Check the server settings with your provider if it does not connect.');
    else wizHead(body, 'google', 'Gmail with an app password', 'Google account → Security → 2-Step Verification → App passwords. Use “Sign in with Google” instead when you can.');
    var name = inp('', 'e.g. Front Office (optional)');
    var email = inp(preEmail || '', 'you@yourcentre.com', 'email');
    var user = inp(found && found.username ? found.username : '', 'Usually the same as the email');
    var pass = inp('', preset ? 'App password' : (manual ? 'Mailbox password' : 'App password'), 'password');
    var two = el('div', 'ec-wz-2');
    two.appendChild(fld('Email address *', email)); two.appendChild(fld('Display name', name));
    two.appendChild(fld('Username', user)); two.appendChild(fld('Password *', pass));
    body.appendChild(two);
    var imapHost, imapPort, imapEnc;
    if (manual) {
      var grid = el('div', 'ec-wz-3');
      imapHost = inp(found ? found.host : '', 'imap.yourhost.com'); imapPort = inp(String(found ? found.port : 993), 'Port'); imapEnc = selEnc(found ? found.encryption : 'ssl');
      grid.appendChild(fld('IMAP server', imapHost)); grid.appendChild(fld('Port', imapPort)); grid.appendChild(fld('Security', imapEnc));
      body.appendChild(grid);
      // Filled in from the address (2026-11-04): only the password is left to type.
      if (found && !preset) body.appendChild(el('div', found.found ? 'ec-wz-ok' : 'ec-wz-warn', found.found ? '✓ Server settings found for this address.' : 'Server settings guessed from the address.'));
    }
    var note = el('div', 'ec-wz-note');
    note.textContent = 'Mail from this mailbox is read here. To reply, use its own mail app - KiddieTrac can only send from Microsoft 365 mailboxes.';
    body.appendChild(note);
    var status = el('div', 'ec-wz-err');
    var row = el('div', 'ec-wz-row');
    var save = el('button', 'ec-pbtn', 'Connect account'); save.type = 'button'; save.setAttribute('data-kt-iconized', '1');
    save.addEventListener('click', function () {
      var em = email.value.trim();
      if (!em || em.indexOf('@') < 1) { status.textContent = 'A valid email address is required.'; email.focus(); return; }
      if (!pass.value) { status.textContent = 'A password is required to connect.'; pass.focus(); return; }
      var payload = { email_address: em, provider: manual ? 'imap' : 'google', display_name: name.value.trim() || null, username: user.value.trim() || em, secret: pass.value };
      if (manual) { payload.imap_host = imapHost.value.trim(); payload.imap_port = parseInt(imapPort.value, 10) || null; payload.imap_encryption = imapEnc.value; }
      save.disabled = true; save.textContent = 'Connecting…'; status.textContent = '';
      api().post(BASE + '/accounts', payload).then(function (r) {
        var id = r && r.account && r.account.id;
        toast('Account added.', 'success');
        (id ? api().post(BASE + '/accounts/' + id + '/test', {}).catch(function () {}) : Promise.resolve()).then(function () { Shell.Modal.close(); openAccounts(); reloadAfterAccounts(); });
      }).catch(function (e) { save.disabled = false; save.textContent = 'Connect account'; status.textContent = (e && e.message) || 'Could not add it.'; });
    });
    row.appendChild(save);
    body.appendChild(row);
    body.appendChild(status);
    if (!preEmail) setTimeout(function () { try { email.focus(); } catch (e) {} }, 60);
    else setTimeout(function () { try { pass.focus(); } catch (e) {} }, 60);
  }
  function selEnc(val) {
    var s = el('select'); s.style.cssText = 'width:100%;height:32px;padding:0 8px;border:1px solid #D5DCE4;border-radius:8px;font-size:13px;background:#fff;font-family:inherit;';
    [['ssl', 'SSL'], ['tls', 'STARTTLS'], ['none', 'None']].forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; if (o[0] === val) op.selected = true; s.appendChild(op); });
    return s;
  }

  function openAccountSettings(a) {
    var body = el('div', 'ec-cmp'); body.setAttribute('data-kt-btn-free', ''); body.setAttribute('data-kt-nofit', ''); body.setAttribute('data-kt-nospin', '');
    Shell.Modal.open({ title: 'Settings — ' + (a.display_name || a.email_address), body: body, large: true });
    var name = inp(a.display_name || '', 'Shown in the mailbox list, e.g. KiddieTrac Sales');
    body.appendChild(fld('Display name', name));
    // Signature: rich text, edited as it will look
    body.appendChild(sectionTitle('Signature'));
    var bar = el('div', 'ec-fmt'); bar.style.borderBottom = 'none';
    var sig = el('div', 'ec-sig-ed ec-scroll'); sig.contentEditable = 'true'; sig.setAttribute('data-kt-scroll', '');
    sig.innerHTML = sigHtml({ signature_html: a.signature_html, signature_enabled: true }) || '';
    [['bold', '<b>B</b>'], ['italic', '<i>I</i>'], ['underline', '<u>U</u>'], ['link', '🔗'], ['removeFormat', 'T̸']].forEach(function (c) {
      var b = el('button', '', c[1]); b.type = 'button'; b.setAttribute('data-kt-iconized', '1');
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () { sig.focus(); if (c[0] === 'link') { var u = window.prompt('Link address (https://…)', 'https://'); if (u && /^(https?:|mailto:)/i.test(u)) document.execCommand('createLink', false, u); return; } document.execCommand(c[0], false, null); });
      bar.appendChild(b);
    });
    body.appendChild(bar); body.appendChild(sig);
    var sigHint = el('div'); sigHint.style.cssText = 'font-size:11.5px;color:#64748B;margin:5px 0 0;'; sigHint.textContent = 'Paste from Outlook or Word to keep your formatting and logo. It is placed in every new message, reply and forward from this mailbox, where you can still change it before sending.';
    body.appendChild(sigHint);
    var sigOn = mkToggle('Add my signature to new messages, replies and forwards', a.signature_enabled !== false);
    body.appendChild(sigOn.wrap);
    // Automatic replies: the mailbox's own (Microsoft 365), not a KiddieTrac copy (2026-11-03)
    body.appendChild(sectionTitle('Automatic replies (out of office)'));
    var auto = autoRepliesPanel(a);
    body.appendChild(auto.el);
    // New-mail alerts for this inbox: on/off and its own sound - mine only, others who share it choose their own (2026-11-05)
    body.appendChild(sectionTitle('New-mail alerts'));
    var alerts = alertsPanel(a);
    body.appendChild(alerts.el);
    body.appendChild(sectionTitle('Preferences'));
    var defOn = mkToggle(salesMode() ? 'Open this mailbox first' : 'Use this as my default account', !!a.is_default);
    body.appendChild(defOn.wrap);

    var status = el('div'); status.style.cssText = 'font-size:12.5px;min-height:16px;margin:8px 0;';
    body.appendChild(status);
    var row = el('div'); row.style.cssText = 'display:flex;justify-content:space-between;gap:8px;margin-top:4px;flex-wrap:wrap;';
    var del = el('button', 'ec-sbtn', '🗑️ Remove account'); del.type = 'button'; del.style.color = '#B91C1C'; del.style.borderColor = '#FECACA';
    del.addEventListener('click', function () {
      if (del.dataset.armed !== '1') { del.dataset.armed = '1'; del.textContent = 'Click again to remove ' + a.email_address; return; }
      api().delete(BASE + '/accounts/' + a.id).then(function () {
        Shell.Modal.close(); toast(a.email_address + ' removed. Its mail stays in Microsoft 365.', 'info');
        if (STATE.acct && STATE.acct.id === a.id) { STATE.acct = null; emptyReading(); }
        reloadAfterAccounts();
      }).catch(function () { toast('Could not remove.', 'info'); });
    });
    var save = el('button', 'ec-pbtn', 'Save settings'); save.type = 'button';
    save.addEventListener('click', function () {
      save.disabled = true; save.textContent = 'Saving…'; status.textContent = '';
      var html = sig.innerHTML.trim(); if (!(sig.innerText || '').trim() && !sig.querySelector('img')) html = '';
      var autoErr = auto.check();
      if (autoErr) { save.disabled = false; save.textContent = 'Save settings'; status.style.color = '#B91C1C'; status.textContent = autoErr; return; }
      api().patch(BASE + '/accounts/' + a.id, {
        display_name: name.value.trim() || null,
        signature_html: html || null, signature_enabled: sigOn.on(), is_default: defOn.on()
      }).then(function () {
        return alerts.save().catch(function (e) { throw { alertsOnly: true, message: (e && e.message) || 'please try again' }; });
      }).then(function () {
        return auto.save().catch(function (e) { throw { autoOnly: true, message: (e && e.message) || 'please try again' }; });
      }).then(function (savedAuto) {
        status.style.color = '#16A34A'; status.textContent = savedAuto ? '✓ Saved. Automatic replies updated in Microsoft 365.' : '✓ Saved.';
        reloadAfterAccounts();
        setTimeout(function () { Shell.Modal.close(); }, savedAuto ? 1100 : 450);
      }).catch(function (e) {
        save.disabled = false; save.textContent = 'Save settings'; status.style.color = '#B91C1C';
        status.textContent = e && e.autoOnly ? 'Your other settings were saved, but the automatic replies were not: ' + e.message
          : e && e.alertsOnly ? 'Your other settings were saved, but the new-mail alerts were not: ' + e.message
          : 'Could not save: ' + ((e && e.message) || 'try again');
        if (e && (e.autoOnly || e.alertsOnly)) reloadAfterAccounts();
      });
    });
    row.appendChild(del); row.appendChild(save);
    body.appendChild(row);
  }
  /* NEW-MAIL ALERTS FOR ONE INBOX (2026-11-05, Anthony: "add option to turn off alerts for mailboxes in mail client and
     add ability to change notification sounds for new mail for each inbox individually"). The person's own choice
     (email_account_alerts): off = no bell, push or sound for this mailbox; a sound = this inbox rings with it instead of
     the Email sound from Settings > Notifications, on the phone and in the open portal. Returns {el, save()}; save()
     resolves false when nothing changed. */
  function alertsPanel(a) {
    var box = el('div');
    var cur = a.alerts || { supported: false, on: true, tone: null };
    if (!cur.supported) {
      var m = el('div'); m.style.cssText = 'font-size:12.5px;color:#475569;line-height:1.55;';
      m.textContent = 'New-mail alerts are for mailboxes connected with Sign in with Microsoft. This one is checked when you open it.';
      box.appendChild(m);
      return { el: box, save: function () { return Promise.resolve(false); } };
    }
    var onT = mkToggle('Alert me when new mail arrives in this inbox (bell, phone and sound)', cur.on !== false);
    box.appendChild(onT.wrap);
    var row = el('div'); row.style.cssText = 'display:flex;align-items:center;gap:6px;max-width:440px;';
    var sel = el('select'); sel.style.cssText = 'flex:1;min-width:0;height:32px;padding:0 8px;border:1px solid #D5DCE4;border-radius:8px;font-size:13px;background:#fff;font-family:inherit;';
    sel.innerHTML = '<option value="">My Email sound</option>';
    if (cur.tone) { var keep = document.createElement('option'); keep.value = cur.tone; keep.textContent = cur.tone; keep.selected = true; sel.appendChild(keep); }
    var play = el('button', 'ec-sbtn', '▶'); play.type = 'button'; play.setAttribute('data-kttip', 'Play this sound'); play.setAttribute('aria-label', 'Play this sound');
    play.style.cssText = 'height:32px;min-width:32px;padding:0 9px;';
    row.appendChild(sel); row.appendChild(play);
    var soundFld = fld('Sound for this inbox', row);
    box.appendChild(soundFld);
    var hint = el('div'); hint.style.cssText = 'font-size:11.5px;color:#64748B;margin:-4px 0 0;line-height:1.5;';
    hint.textContent = 'Only for you' + (salesMode() ? ' - everyone else on the sales team keeps their own choice.' : '.') + ' On a phone, the app plays it from the next new email.';
    box.appendChild(hint);
    var urls = {}, emailTone = null;
    function sync() { var on = onT.on(); soundFld.style.opacity = on ? '' : '.5'; sel.disabled = !on; play.disabled = !on; }
    onT.wrap.addEventListener('change', sync); sync();
    api().get('/me/notification-sounds').then(function (d) {
      var tones = (d && d.tones) || [];
      emailTone = d && d.choices && d.choices.email && d.choices.email.tone;
      var names = {}; tones.forEach(function (t) { urls[t.key] = t.url; names[t.key] = t.label; });
      sel.innerHTML = '';
      var def = document.createElement('option'); def.value = ''; def.textContent = 'My Email sound' + (emailTone && names[emailTone] ? ' (' + names[emailTone] + ')' : ''); sel.appendChild(def);
      var groups = {};
      tones.forEach(function (t) {
        var g = groups[t.group]; if (!g) { g = groups[t.group] = document.createElement('optgroup'); g.label = t.group || 'Sounds'; sel.appendChild(g); }
        var o = document.createElement('option'); o.value = t.key; o.textContent = t.label; g.appendChild(o);
      });
      sel.value = cur.tone && urls[cur.tone] ? cur.tone : '';
    }).catch(function () {});
    play.addEventListener('click', function () {
      var k = sel.value || emailTone || 'kt_notify';
      var u = urls[k] || ('/sounds/' + k + '.wav');
      if (KT.sounds && KT.sounds.preview) KT.sounds.preview(u); else { try { new Audio(u).play(); } catch (e) {} }
    });
    return {
      el: box,
      save: function () {
        var on = onT.on(), tone = sel.value || null;
        if (on === (cur.on !== false) && (tone || null) === (cur.tone || null)) return Promise.resolve(false);
        return api().put(BASE + '/accounts/' + a.id + '/alerts', { on: on, tone: tone }).then(function (r) {
          if (r && r.alerts) { cur = r.alerts; a.alerts = r.alerts; }
          return true;
        });
      }
    };
  }
  /* The Automatic replies part of a mailbox's settings: read from Microsoft 365 (/auto-replies), so replies set
     earlier in Outlook show here, and saved back to the mailbox. Returns {el, check(), save()}: save() resolves
     false when nothing changed, true once Microsoft has the new setting. */
  function autoRepliesPanel(a) {
    var box = el('div'); box.style.cssText = 'border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;background:#F8FAFC;';
    var note = el('div'); note.style.cssText = 'font-size:12.5px;color:#475569;line-height:1.5;';
    note.textContent = 'Reading the automatic replies from ' + a.email_address + '…';
    box.appendChild(note);
    var S = null, F = null;
    var taCss = 'width:100%;min-height:190px !important;height:190px;padding:10px 12px;border:1px solid #D5DCE4;border-radius:8px;font-size:13px;font-family:inherit;box-sizing:border-box;resize:vertical;background:#fff;';
    function selectOf(opts, val) {
      var s = el('select'); s.style.cssText = 'width:100%;height:32px;padding:0 8px;border:1px solid #D5DCE4;border-radius:8px;font-size:13px;background:#fff;font-family:inherit;';
      opts.forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; if (o[0] === val) op.selected = true; s.appendChild(op); });
      return s;
    }
    function summary(st) {
      if (st.status === 'alwaysEnabled') return '🌴 <strong>On</strong> - replies are going out now.';
      if (st.status === 'scheduled') return (st.active ? '🌴 <strong>On now</strong>, scheduled' : '🗓️ <strong>Scheduled</strong>') + ': ' + esc(st.start_label || '?') + ' to ' + esc(st.end_label || '?') + '.';
      return '<strong>Off</strong> - no automatic replies are being sent.';
    }
    function draw(st) {
      S = st; F = null; box.innerHTML = '';
      if (!st.supported || !st.can_edit) {
        var m = el('div'); m.style.cssText = 'font-size:12.5px;color:#475569;line-height:1.55;'; m.textContent = st.message || 'Automatic replies cannot be read for this mailbox.';
        box.appendChild(m);
        if (st.needs_consent) {
          var rb = el('button', 'ec-pbtn', '🔌 Reconnect to allow'); rb.type = 'button'; rb.style.marginTop = '9px';
          var rs = el('div'); rs.style.cssText = 'font-size:12px;color:#B91C1C;margin-top:5px;';
          rb.addEventListener('click', function () { startMicrosoft(a.id, rb, rs); });
          box.appendChild(rb); box.appendChild(rs);
        }
        return;
      }
      var head = el('div'); head.style.cssText = 'font-size:13px;color:#334155;margin-bottom:9px;line-height:1.5;';
      head.innerHTML = summary(st) + '<div style="font-size:11.5px;color:#64748B;margin-top:2px;">From Microsoft 365 - the same automatic replies as in Outlook. Times in ' + esc(st.tz) + '.</div>';
      box.appendChild(head);
      F = {};
      F.status = selectOf([['disabled', 'Off'], ['alwaysEnabled', 'On'], ['scheduled', 'On, only during these times']], st.status);
      box.appendChild(fld('Automatic replies', F.status));
      var times = el('div'); times.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0 8px;';
      F.start = inp(st.start || '', '', 'datetime-local'); F.end = inp(st.end || '', '', 'datetime-local');
      times.appendChild(fld('Start', F.start)); times.appendChild(fld('End', F.end));
      box.appendChild(times);
      // Inside / outside the organisation as two sub-tabs, as Outlook shows them (standard band: kt-tab-band adopts it).
      var tabs = el('div'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', 'Automatic reply messages'); tabs.style.marginTop = '4px';
      var panes = {};
      [['in', 'Inside my organisation'], ['out', 'Outside my organisation']].forEach(function (t, i) {
        var b = el('button', '', t[1]); b.type = 'button'; b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
        b.addEventListener('click', function () {
          Array.prototype.forEach.call(tabs.children, function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
          Object.keys(panes).forEach(function (k) { panes[k].style.display = k === t[0] ? '' : 'none'; });
        });
        tabs.appendChild(b);
        panes[t[0]] = el('div'); panes[t[0]].setAttribute('role', 'tabpanel'); panes[t[0]].style.cssText = 'padding-top:8px;' + (i === 0 ? '' : 'display:none;');
      });
      box.appendChild(tabs); box.appendChild(panes.in); box.appendChild(panes.out);
      F.internal = el('textarea'); F.internal.style.cssText = taCss; F.internal.value = st.internal_message || ''; F.internal.placeholder = 'I’m away and will reply when I return.';
      panes.in.appendChild(fld('Reply sent to people in my organisation', F.internal));
      F.audience = selectOf([['all', 'Reply to everyone outside my organisation'], ['contactsOnly', 'Reply only to my contacts outside'], ['none', 'Do not reply outside my organisation']], st.audience || 'all');
      panes.out.appendChild(fld('Who gets a reply', F.audience));
      F.external = el('textarea'); F.external.style.cssText = taCss; F.external.value = st.external_message || ''; F.external.placeholder = 'Thank you for your email. I’m away and will reply when I return.';
      var extWrap = fld('Reply sent to people outside', F.external); panes.out.appendChild(extWrap);
      var hint = el('div'); hint.style.cssText = 'font-size:11.5px;color:#64748B;line-height:1.45;';
      hint.textContent = 'A message you change here is saved as plain text; one you leave alone keeps the formatting it has in Outlook.';
      box.appendChild(hint);
      function sync() {
        var on = F.status.value !== 'disabled';
        times.style.display = F.status.value === 'scheduled' ? '' : 'none';
        extWrap.style.display = F.audience.value === 'none' ? 'none' : '';
        [F.internal, F.external, F.audience].forEach(function (x) { x.disabled = !on; x.style.opacity = on ? '' : '.6'; });
      }
      F.status.addEventListener('change', sync); F.audience.addEventListener('change', sync); sync();
    }
    function changed() {
      if (!F || !S) return null;
      var c = {};
      if (F.status.value !== S.status) c.status = 1;
      if (F.audience.value !== (S.audience || 'all')) c.audience = 1;
      if (F.status.value === 'scheduled' && (F.start.value !== (S.start || '') || F.end.value !== (S.end || ''))) c.times = 1;
      if (F.internal.value.trim() !== (S.internal_message || '').trim()) c.internal = 1;
      if (F.external.value.trim() !== (S.external_message || '').trim()) c.external = 1;
      return Object.keys(c).length ? c : null;
    }
    api().get(BASE + '/accounts/' + a.id + '/auto-replies').then(draw).catch(function (e) {
      draw({ supported: false, can_edit: false, message: (e && e.message) || 'The automatic replies could not be read right now.' });
    });
    return {
      el: box,
      check: function () {
        if (!changed() || F.status.value !== 'scheduled') return '';
        if (!F.start.value || !F.end.value) return 'Pick when the automatic replies start and end.';
        if (F.end.value <= F.start.value) return 'The automatic replies have to end after they start.';
        return '';
      },
      save: function () {
        var c = changed();
        if (!c) return Promise.resolve(false);
        var body = { status: F.status.value, audience: F.audience.value };
        if (F.status.value === 'scheduled') { body.start = F.start.value; body.end = F.end.value; }
        if (c.internal) body.internal_message = F.internal.value;
        if (c.external) body.external_message = F.external.value;
        return api().put(BASE + '/accounts/' + a.id + '/auto-replies', body).then(function (st) { if (st) draw(st); return true; });
      }
    };
  }
  function sectionTitle(t) { var d = el('div'); d.style.cssText = 'font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#0C6070;margin:14px 0 7px;'; d.textContent = t; return d; }
  function mkToggle(label, on) {
    var wrap = el('label'); wrap.style.cssText = 'display:flex;align-items:center;gap:9px;margin:7px 0;cursor:pointer;font-size:13px;color:#334155;';
    var cb = el('input'); cb.type = 'checkbox'; cb.checked = !!on; cb.style.cssText = 'width:16px;height:16px;';
    var sp = el('span'); sp.textContent = label;
    wrap.appendChild(cb); wrap.appendChild(sp);
    return { wrap: wrap, on: function () { return cb.checked; } };
  }

  KT.EmailClient = { render: render, openAccounts: openAccounts };
  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'platform_admin', 'centre_director', 'educator'].forEach(function (r) {
      Shell.registerScreen(r + ':email', render);
    });
    // Sales -> Email: the sales team and superadmins (platform_admin runs in the agency_admin shell). The API refuses
    // anyone else; this only keeps the screen from drawing for them.
    var salesOk = function () {
      try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); var r = u.roles || []; return r.indexOf('sales_rep') > -1 || r.indexOf('platform_admin') > -1; } catch (e) { return false; }
    };
    ['sales_rep', 'platform_admin', 'agency_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':sales-email', function (main, ctx) {
        if (!salesOk()) { main.innerHTML = '<div style="padding:48px 20px;text-align:center;color:#64748B">🔒 The sales mailbox is for the sales team and superadmins.</div>'; return; }
        return render(main, ctx);
      });
    });
  }
})(window);
