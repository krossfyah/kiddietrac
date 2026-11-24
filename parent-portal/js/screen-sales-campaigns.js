/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Sales → Campaigns (2026-10-26).

   Anthony: "create a sales campaign section to reach out to prospects/leads ... introducing kiddietrac and our
   services/products and add the quick intro video - make this email look good and the campaign function highly
   usable to track emails, leads and follow-up if someone responds and adds to leads."

   #sales-campaigns            every campaign with its numbers
   #sales-campaign?id=N        one campaign: Email (editor + live preview), People (who gets it, what each did),
                               Results (the funnel and who needs a follow-up)

   The email itself, the tracking and the response → lead → follow-up path are server-side
   (App\Support\SalesCampaigns). Sending happens in small batches from sales@ once the person confirms the count.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;
  var TEAL = '#0E8F99', NAVY = '#0B1F4D';
  var OPTS = null;   // videos, highlights, consent (from GET /sales/campaigns)

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === 'style') e.style.cssText = v;
      else if (k === 'html') e.innerHTML = v;
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
      else e.setAttribute(k, v === true ? '' : v);
    });
    (kids || []).forEach(function (c) { if (c != null) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(icon, title, msg, color) { try { KT.toast && KT.toast(icon, title, msg || '', color || TEAL); } catch (e) {} }
  function go(h) { window.location.hash = '#' + h; }
  function me() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function salesOk() { var r = me().roles || []; return r.indexOf('sales_rep') > -1 || r.indexOf('platform_admin') > -1; }
  function tok() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return KT.API_BASE || 'https://api.kiddietrac.com/api/v1'; }
  function when(s) { if (!s) return '—'; var d = new Date(String(s).replace(' ', 'T') + (/[zZ+]/.test(s) ? '' : 'Z')); return isNaN(d) ? s : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); }
  function modal(title, body, large) { KT.Shell.Modal.open({ title: title, body: body, large: !!large }); }
  function closeModal() { try { KT.Shell.Modal.close(); } catch (e) {} }

  function css() {
    if (document.getElementById('kt-camp-css')) return;
    var s = document.createElement('style'); s.id = 'kt-camp-css';
    s.textContent = [
      '.kc-wrap{padding:16px 20px 40px}',
      '.kc-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}',
      '.kc-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:4px 0 16px}',
      '.kc-tile{background:#fff;border:1px solid #E5EAF1;border-radius:14px;padding:14px 16px}',
      '.kc-tile b{display:block;font-size:24px;color:' + NAVY + ';line-height:1.1}.kc-tile span{font-size:12px;color:#64748B;font-weight:700;letter-spacing:.04em;text-transform:uppercase}',
      /* COLOURED STAT TILES (2026-11-05, Anthony): a light colour per tile, its number in the deeper shade. */
      '.kc-tiles .kc-tile:nth-child(5n+1){background:#EFF6FF;border-color:#BFDBFE}.kc-tiles .kc-tile:nth-child(5n+1) b{color:#1D4ED8}',
      '.kc-tiles .kc-tile:nth-child(5n+2){background:#F0FDFA;border-color:#99F6E4}.kc-tiles .kc-tile:nth-child(5n+2) b{color:#0F766E}',
      '.kc-tiles .kc-tile:nth-child(5n+3){background:#F0FDF4;border-color:#BBF7D0}.kc-tiles .kc-tile:nth-child(5n+3) b{color:#15803D}',
      '.kc-tiles .kc-tile:nth-child(5n+4){background:#FAF5FF;border-color:#E9D5FF}.kc-tiles .kc-tile:nth-child(5n+4) b{color:#7E22CE}',
      '.kc-tiles .kc-tile:nth-child(5n+5){background:#FFF7ED;border-color:#FED7AA}.kc-tiles .kc-tile:nth-child(5n+5) b{color:#C2410C}',
      '.kc-card{background:#fff;border:1px solid #E5EAF1;border-radius:16px;padding:16px 18px;margin-bottom:12px}',
      '.kc-btn{border:1px solid #CBD5E1;background:#fff;color:#1E293B;border-radius:10px;padding:8px 14px;font:700 13px/1.2 inherit;cursor:pointer;white-space:nowrap}',
      '.kc-btn:hover{background:#F8FAFC}.kc-btn.p{background:linear-gradient(135deg,#0E8F99,#1F6080);color:#fff;border-color:transparent}',
      '.kc-btn.warn{background:#FEF3C7;border-color:#FCD34D;color:#92400E}.kc-btn[disabled]{opacity:.5;cursor:not-allowed}',
      '.kc-pill{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:800;letter-spacing:.02em;white-space:nowrap}',
      '.kc-list .kc-camp{display:grid;grid-template-columns:minmax(0,2.2fr) repeat(4,minmax(80px,1fr)) auto;gap:14px;align-items:center;cursor:pointer}',
      '.kc-list .kc-camp:hover{border-color:#94D3D8}',
      /* LIGHT STATUS TINT (2026-11-05, Anthony: "campaigns listing should be colourized lightly to differentiate"):
         a pale wash per status, no colour band; the status pill keeps the words. */
      '.kc-list .kc-camp.kc-t-draft{background:#F8FAFC}',
      '.kc-list .kc-camp.kc-t-sending{background:#F0F9FF}',
      '.kc-list .kc-camp.kc-t-paused{background:#FFFBEB}',
      '.kc-list .kc-camp.kc-t-scheduled_pending{background:#FFFBEB}',
      '.kc-list .kc-camp.kc-t-scheduled_approved{background:#F0FDFA}',
      '.kc-list .kc-camp.kc-t-sent{background:#F0FDF4}',
      '.kc-list .kc-camp.kc-t-sub{background:#FAF5FF}',
      '.kc-list .kc-camp{position:relative}',
      '@media(max-width:1100px){.kc-list .kc-camp > .kt-ka-kebab{position:absolute;top:10px;right:10px}.kc-list .kc-camp > div:first-child{padding-right:44px}}',
      '.kc-num{font-size:18px;font-weight:800;color:' + NAVY + '}.kc-sub{font-size:12px;color:#64748B}',
      '.kc-bar{height:6px;border-radius:6px;background:#EEF2F6;overflow:hidden;margin-top:5px}.kc-bar i{display:block;height:100%;background:linear-gradient(90deg,#1DB9C3,#6DB33F)}',
      '.kc-tabs{display:flex;gap:6px;border-bottom:1px solid #E2E8F0;margin:6px 0 16px}',
      '.kc-tab{border:0;background:none;padding:10px 14px;font:800 14px/1 inherit;color:#64748B;cursor:pointer;border-bottom:2px solid transparent}',
      '.kc-tab.on{color:' + TEAL + ';border-bottom-color:' + TEAL + '}',
      '.kc-edit{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:18px;align-items:start}',
      '.kc-f{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;font-size:13px;font-weight:700;color:#334155}',
      '.kc-f input,.kc-f select,.kc-f textarea{width:100%!important;max-width:none!important;box-sizing:border-box;padding:9px 11px;border:1.5px solid #E2E8F0;border-radius:10px;font:500 14px/1.4 inherit;color:#0F172A;background:#fff}',
      '.kc-f small{font-weight:500;color:#64748B}',
      '.kc-hl{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px}',
      '.kc-hl label{display:flex;gap:8px;align-items:flex-start;border:1.5px solid #E2E8F0;border-radius:10px;padding:8px 10px;font-weight:600;font-size:13px;cursor:pointer}',
      '.kc-hl label.on{border-color:' + TEAL + ';background:#EFFAFA}',
      '.kc-prev{position:sticky;top:84px;background:#EEF2F7;border-radius:16px;border:1px solid #E2E8F0;overflow:hidden}',
      '.kc-prev iframe{width:100%;height:calc(100vh - 190px);min-height:560px;border:0;display:block;background:#EEF2F7}',
      '.kc-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}.kc-chip{border:1px solid #E2E8F0;background:#fff;border-radius:999px;padding:5px 11px;font:700 12.5px/1 inherit;color:#475569;cursor:pointer}',
      '.kc-chip.on{background:' + NAVY + ';border-color:' + NAVY + ';color:#fff}',
      '.kc-tbl{width:100%;border-collapse:collapse;font-size:13.5px}.kc-tbl th{text-align:left;font-size:11.5px;color:#64748B;font-weight:800;letter-spacing:.04em;text-transform:uppercase;padding:8px 10px;border-bottom:1px solid #E2E8F0}',
      '.kc-tbl td{padding:9px 10px;border-bottom:1px solid #F1F5F9;vertical-align:middle}',
      '.kc-funnel{display:grid;gap:10px}.kc-fstep{display:grid;grid-template-columns:130px minmax(0,1fr) 90px;gap:12px;align-items:center;font-weight:700;color:#334155}',
      '.kc-fstep .kc-bar{height:14px;margin:0}',
      '.kc-lbl{font-size:11.5px;font-weight:800;color:#64748B;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px}',
      '.kc-tplbar select{height:32px;padding:4px 10px;border:1.5px solid #E2E8F0;border-radius:10px;font:600 13.5px/1.2 inherit;color:#0F172A;background:#fff}',
      '.kc-tplbar .kc-btn{height:32px;padding:0 12px}',
      '.kc-tpl-edit{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:16px;align-items:start}',
      '.kc-tpl-edit iframe{width:100%;height:62vh;min-height:420px;border:1px solid #E2E8F0;border-radius:12px;background:#EEF2F7;display:block}',
      '.kc-tpl-tbl tr[data-tpl]{cursor:pointer}.kc-tpl-tbl tr[data-tpl]:hover td{background:#F8FAFC}',
      '@media(max-width:900px){.kc-tpl-edit{grid-template-columns:1fr}}',
      /* the "Your own email" design panel (2026-11-05) */
      '.kc-cv{border:1.5px solid #E2E8F0;border-radius:12px;padding:12px 14px;margin:0 0 12px;background:#FBFCFE}',
      '.kc-cv-h{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 10px;font-size:13.5px;font-weight:800;line-height:1.2;color:#0F172A}',
      '.kc-cv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(128px,1fr));gap:8px;margin:0 0 10px}.kc-cv-grid.two{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:4px 16px}',
      '.kc-rte-sm .kc-rte-ed{min-height:84px}',
      '.kc-cv-col{display:flex;align-items:center;gap:8px;border:1.5px solid #E2E8F0;border-radius:10px;padding:2px 8px;min-height:32px;box-sizing:border-box;font-size:13px;font-weight:600;line-height:1.2;color:#334155;background:#fff;cursor:pointer}',
      '.kc-cv-chk{display:flex;align-items:flex-start;gap:12px;padding:4px 0;cursor:pointer}.kc-cv-chk b{display:block;font-size:14px;font-weight:600;color:#334155;line-height:1.3}.kc-cv-chk small{display:block;font-size:12.5px;font-weight:400;color:#64748B;margin-top:2px;line-height:1.35}',
      '#appMain .kc-cv-col input[type=color],.kc-cv-col input[type=color]{flex:0 0 auto;width:26px!important;height:26px!important;min-width:0!important;min-height:0!important;max-width:none!important;padding:1px!important;border:1px solid #CBD5E1!important;border-radius:6px!important;background:#fff;cursor:pointer}',
      '.kc-cv .kc-f{margin-bottom:10px}.kc-cv-note{font-size:12px;color:#64748B;margin:-4px 0 10px;font-weight:500}',
      '@media(max-width:1100px){.kc-edit{grid-template-columns:1fr}.kc-prev{position:static}.kc-list .kc-camp{grid-template-columns:1fr 1fr}}',
    ].join('');
    document.head.appendChild(s);
  }

  var STATUS = {
    draft: ['Draft', '#F1F5F9', '#475569'], sending: ['Sending', '#E0F2FE', '#075985'], paused: ['Paused', '#FEF3C7', '#92400E'], sent: ['Sent', '#DCFCE7', '#166534'],
    // approve + schedule (2026-10-27)
    scheduled_pending: ['Scheduled – awaiting approval', '#FEF3C7', '#92400E'], scheduled_approved: ['Scheduled – approved', '#DCFCE7', '#166534'],
  };
  function st(c) { return c.display_status || c.status; }
  var RSTATUS = {
    queued: ['Waiting', '#F1F5F9', '#475569'], sending: ['Sending', '#E0F2FE', '#075985'], sent: ['Sent', '#DCFCE7', '#166534'],
    failed: ['Failed', '#FEE2E2', '#991B1B'], skipped: ['Skipped', '#F1F5F9', '#64748B'],
  };
  var RESP = {
    interested: ['🙋 Interested', '#EDE9FE', '#5B21B6'], replied: ['💬 Replied', '#DBEAFE', '#1E40AF'], booked: ['📅 Booked a demo', '#DCFCE7', '#166534'],
    unsubscribed: ['🚫 Unsubscribed', '#FEE2E2', '#991B1B'],
  };
  function pill(map, key) { var p = map[key] || [key || '—', '#F1F5F9', '#475569']; return el('span', { class: 'kc-pill', style: 'background:' + p[1] + ';color:' + p[2] }, [p[0]]); }

  /* ───────────────────────────── list ───────────────────────────── */
  async function renderList(main) {
    css(); main.innerHTML = '';
    var wrap = el('div', { class: 'kc-wrap', 'data-kt-screen': 'sales-campaigns' }); main.appendChild(wrap);
    var d;
    try { d = await Api.get('/sales/campaigns'); } catch (e) { wrap.appendChild(el('div', { class: 'kc-card', style: 'color:#B91C1C' }, ['Could not load campaigns: ' + (e.message || e)])); return; }
    OPTS = d.options;
    var cs = d.campaigns || [];
    var sum = function (k) { return cs.reduce(function (a, c) { return a + (c.counts[k] || 0); }, 0); };
    var sent = sum('sent');
    wrap.appendChild(el('div', { class: 'kc-row', style: 'justify-content:space-between;margin:2px 0 12px' }, [
      el('div', { style: 'color:#475569;font-size:14px;max-width:760px' }, ['Every KiddieTrac email campaign: prospect introductions with the intro film, and emails to the KiddieTrac subscriber list. See who opened, clicked and answered; anyone who responds goes into Leads with a follow-up task.']),
      el('div', { class: 'kc-row' }, [
        iconized(el('button', { class: 'kc-btn', onclick: function () { manageTemplates(function () { renderList(main); }); } }, ['🗂 Email templates'])),
        el('button', { class: 'kc-btn p', onclick: newCampaign }, ['➕ New campaign']),
      ]),
    ]));
    wrap.appendChild(el('div', { class: 'kc-tiles' }, [
      tile(cs.length, 'Campaigns'), tile(sent, 'Emails sent'),
      tile(sent ? Math.round(sum('opened') * 100 / sent) + '%' : '—', 'Opened'),
      tile(sum('responded'), 'Responses'), tile(d.suppressed || 0, 'Unsubscribed'),
    ]));
    if (!cs.length) {
      wrap.appendChild(el('div', { class: 'kc-card', style: 'text-align:center;padding:40px 20px' }, [
        el('div', { style: 'font-size:40px' }, ['📣']),
        el('h3', { style: 'margin:8px 0 4px;color:' + NAVY }, ['No campaigns yet']),
        el('p', { style: 'color:#64748B;margin:0 0 16px' }, ['Start one, add your prospects, send yourself a test, then send it from sales@kiddietrac.com.']),
        el('button', { class: 'kc-btn p', onclick: newCampaign }, ['➕ New campaign']),
      ]));
      return;
    }
    var list = el('div', { class: 'kc-list' });
    cs.forEach(function (c) {
      var rate = function (k) { return el('div', {}, [el('div', { class: 'kc-num' }, [c.counts.sent ? c.rates[k] + '%' : '—']), el('div', { class: 'kc-sub' }, [{ opened: 'opened', clicked: 'clicked', responded: 'responded' }[k]]),
        el('div', { class: 'kc-bar' }, [el('i', { style: 'width:' + (c.rates[k] || 0) + '%' })])]); };
      list.appendChild(el('div', { class: 'kc-card kc-camp kc-t-' + (c.template && st(c) === 'draft' ? 'sub' : String(st(c) || 'draft').replace(/[^a-z_]/g, '')), onclick: function () { go('sales-campaign?id=' + c.id); } }, [
        el('div', { style: 'min-width:0' }, [
          el('div', { class: 'kc-row' }, [el('b', { style: 'font-size:15.5px;color:' + NAVY }, [c.name]), pill(STATUS, st(c)),
            c.template ? el('span', { class: 'kc-pill', style: 'background:#EDE9FE;color:#5B21B6' }, ['📬 Subscriber email: ' + c.template_label]) : null]),
          el('div', { class: 'kc-sub', style: 'margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis' }, [c.subject]),
          el('div', { class: 'kc-sub', style: 'margin-top:2px' }, [c.sent_at ? 'Sent ' + when(c.sent_at) : (c.status === 'sending' ? c.queued + ' still to go'
            : c.scheduled_label ? '🗓 ' + c.scheduled_label + (c.approved_at ? ' · approved' + (c.approved_by ? ' by ' + c.approved_by : '') : ' · needs approval')
            : 'Created ' + when(c.created_at) + (c.created_by ? ' by ' + c.created_by : ''))]),
        ]),
        el('div', {}, [el('div', { class: 'kc-num' }, [String(c.counts.sent) + (c.counts.recipients > c.counts.sent ? ' / ' + c.counts.recipients : '')]), el('div', { class: 'kc-sub' }, ['sent'])]),
        rate('opened'), rate('clicked'), rate('responded'),
        campMenuBtn(c, main),
      ]));
    });
    wrap.appendChild(list);
  }

  /* THE CARD'S ⋮ (2026-10-28, Anthony: "use the kebab instead of the open to open, view campaign, delete, and
     any other shortcuts to other sections within the campaign"). Campaigns are cards, not a table, so
     kt-row-actions has nothing to collapse: this is the one hand-built menu on the screen, drawn by the shared
     KT.openActionMenu so it looks and behaves like every other ⋮. A tap on the card itself still opens it. */
  function campMenuBtn(c, main) {
    var open = function (tab) { go('sales-campaign?id=' + c.id + (tab ? '&tab=' + tab : '')); };
    var item = function (label, fn) { return el('button', { type: 'button', onclick: fn }, [label]); };
    var btn = el('button', { type: 'button', class: 'kt-ka-kebab', 'aria-label': 'Campaign actions', title: 'Actions', onclick: function (ev) {
      ev.stopPropagation();
      var acts = [
        item('📂 Open campaign', function () { open(''); }),
        item('✉️ Email & preview', function () { open('email'); }),
        item('👥 People (' + (c.counts.recipients || 0) + ')', function () { open('people'); }),
        item('📈 Results', function () { open('results'); }),
        item('🧪 Send me a test', function () { testSend(c); }),
        item('⧉ Copy to a new campaign', function () { copyCampaign(c); }),
      ];
      if (c.status !== 'sending' && c.status !== 'sent') {
        acts.push(item(c.scheduled_for ? '🗓 Schedule & approval' : '🗓 Schedule', function () { open('email'); }));
      }
      if (c.status !== 'sending') {
        acts.push(item('🗑 Delete campaign', async function () {
          var ok = KT.confirm ? await KT.confirm('Delete the campaign "' + c.name + '"?', {
            description: c.counts.sent ? 'Its results stay in the audit log. Nobody else will receive it.' : 'Nobody on its list will receive it.' })
            : window.confirm('Delete the campaign "' + c.name + '"?');
          if (!ok) return;
          try { await Api.delete('/sales/campaigns/' + c.id); toast('🗑', 'Campaign deleted', c.name); renderList(main); }
          catch (e) { toast('⚠️', 'Could not delete it', e.message, '#DC2626'); }
        }));
      }
      if (KT.openActionMenu) KT.openActionMenu(btn, acts); else open('');
    } }, ['⋮']);
    return btn;
  }
  /* COPY A CAMPAIGN (2026-11-11, Anthony: "add ability to copy an existing campaign to a new campaign as well"). A new
     draft with the same email (POST /sales/campaigns/{id}/copy); the people on it come too only when asked. Opens the
     copy. From the card's ⋮ and the campaign's header. */
  function copyCampaign(c) {
    var n = (c.counts && c.counts.recipients) || c.recipient_count || 0;
    var nm = el('input', { type: 'text', maxlength: '160', 'aria-label': 'Name of the new campaign' });
    nm.value = ('Copy of ' + c.name).slice(0, 160);
    var withP = el('input', { type: 'checkbox', style: 'width:16px;height:16px;flex:none' });
    var b = el('button', { class: 'kc-btn p' }, ['⧉ Make the copy']);
    b.addEventListener('click', async function () {
      b.disabled = true;
      try {
        var r = await Api.post('/sales/campaigns/' + c.id + '/copy', { name: nm.value.trim(), with_people: withP.checked });
        closeModal();
        toast('⧉', 'Campaign copied', r.campaign.name + (withP.checked ? ' · ' + r.people + ' ' + (r.people === 1 ? 'person' : 'people') + (r.skipped ? ' (' + r.skipped + ' unsubscribed, skipped)' : '') : ''));
        go('sales-campaign?id=' + r.campaign.id);
      } catch (e) { b.disabled = false; toast('⚠️', 'Could not copy it', e.message, '#DC2626'); }
    });
    modal('⧉ Copy campaign', el('div', {}, [
      el('p', { class: 'kc-sub', style: 'margin:0 0 10px' }, ['A new draft with the same email: wording, layout, design, highlights, video and sender. It has its own schedule and results, and nothing is sent until you send it.']),
      el('label', { class: 'kc-f' }, ['Name of the new campaign', nm]),
      n ? el('label', { class: 'kc-row', style: 'font-size:13px;font-weight:700;margin:10px 0;cursor:pointer;gap:8px;align-items:center' },
        [withP, 'Also copy the ' + n + ' ' + (n === 1 ? 'person' : 'people') + ' on it (anyone who has unsubscribed since is skipped)']) : null,
      el('div', { style: 'margin-top:10px' }, [b]),
    ]));
    setTimeout(function () { try { nm.focus(); nm.select(); } catch (e) {} }, 60);
  }
  function tile(v, label) { return el('div', { class: 'kc-tile' }, [el('span', {}, [label]), el('b', {}, [String(v)])]); }

  /* NEW CAMPAIGN = A NAME AND WHEN (2026-11-04, Anthony: "you have email field which is not needed as the next step is
     to choose all of that info. perhaps add a scheduler"). The email itself - a KiddieTrac preset, one of the team's
     templates, or your own words - is picked on the campaign's Email tab, which opens next. */
  function newCampaign() {
    var name = el('input', { placeholder: 'e.g. Ontario centres: October intro', value: '' });
    var whenSel = el('select');
    whenSel.appendChild(el('option', { value: '' }, ['I will send or schedule it later']));
    whenSel.appendChild(el('option', { value: 'at' }, ['Schedule it for a date and time']));
    var at = el('input', { type: 'datetime-local' });
    var atRow = el('label', { class: 'kc-f', style: 'display:none' }, ['Send at (Eastern Time)', el('small', {}, ['It goes out at this time once it has been approved.']), at]);
    whenSel.addEventListener('change', function () { atRow.style.display = whenSel.value === 'at' ? '' : 'none'; if (whenSel.value === 'at') { try { at.focus(); } catch (e) {} } });
    var start = el('select');
    start.appendChild(el('option', { value: 'custom' }, ['A blank email: I design it myself (header, footer, pictures, colours)']));
    start.appendChild(el('option', { value: 'standard' }, ['The KiddieTrac introduction, or a template I pick next']));
    var status = el('div', { style: 'color:#B91C1C;font-size:12.5px;min-height:16px;margin:4px 0 8px' });
    var b = el('button', { class: 'kc-btn p', onclick: async function () {
      if (!name.value.trim()) { status.textContent = 'Give the campaign a name (only your team sees it).'; name.focus(); return; }
      if (whenSel.value === 'at' && !at.value) { status.textContent = 'Pick the date and time, or choose to schedule it later.'; at.focus(); return; }
      b.disabled = true;
      var r;
      try { r = await Api.post('/sales/campaigns', start.value === 'custom' ? { name: name.value.trim(), design: 'custom', intro: '<p>Hi {first_name},</p><p><br></p>' } : { name: name.value.trim() }); }
      catch (e) { b.disabled = false; status.textContent = e.message || 'Could not create it.'; return; }
      if (whenSel.value === 'at') {
        try { await Api.post('/sales/campaigns/' + r.campaign.id + '/schedule', { send_at: at.value.slice(0, 16) }); }
        catch (e) { toast('⚠️', 'Created, but not scheduled', (e.message || '') + ' Use 🗓 Schedule on the campaign.', '#D97706'); }
      }
      closeModal(); go('sales-campaign?id=' + r.campaign.id + '&tab=email');
    } }, ['Create campaign']);
    modal('📣 New campaign', el('div', {}, [
      el('label', { class: 'kc-f' }, ['Campaign name', el('small', {}, ['Only your team sees this.']), name]),
      el('label', { class: 'kc-f' }, ['Start with', start]),
      el('label', { class: 'kc-f' }, ['When it goes out', whenSel]),
      atRow,
      el('p', { style: 'font-size:13px;color:#64748B;margin:0 0 6px' }, ['Next you choose the email: a KiddieTrac preset, one of your templates, or your own words. Then you add the people.']),
      status, b,
    ]));
    setTimeout(function () { try { name.focus(); } catch (e) {} }, 60);
  }
  function iconized(b) { b.dataset.ktIconized = '1'; return b; }   // keeps its words (kt-icon-buttons would leave a bare icon)

  /* The layout picker (2026-11-05): layouts you write in first ("Your own email" leading), finished subscriber designs
     apart and labelled as subject-only - "Diwali 2026" was made on the One-Tap design by mistake and had no editor. */
  function layoutSelect(current, withFinished) {
    var s = el('select', { 'aria-label': 'Layout' });
    var all = (OPTS && OPTS.layouts) || [{ key: 'standard', label: 'Standard introduction (with the film)' }];
    var mine = all.filter(function (l) { return !/^sub:/.test(l.key); }).sort(function (a, b) { return (b.key === 'custom') - (a.key === 'custom'); });
    var g1 = el('optgroup', { label: 'Write your own words' });
    mine.forEach(function (l) { var o = el('option', { value: l.key }, [l.label]); if (l.key === current) o.selected = true; g1.appendChild(o); });
    s.appendChild(g1);
    var fin = all.filter(function (l) { return /^sub:/.test(l.key); });
    if (withFinished && fin.length) {
      var g2 = el('optgroup', { label: 'Finished KiddieTrac designs (only the subject changes)' });
      fin.forEach(function (l) { var o = el('option', { value: l.key }, [l.label]); if (l.key === current) o.selected = true; g2.appendChild(o); });
      s.appendChild(g2);
    }
    return s;
  }

  /* THE RICH EDITOR (2026-11-04, Anthony: "I don't see a rich editor to edit the campaign template/email"). The
     Introduction - and the whole body of "Your own email" - is written here: text style, bold/italic/underline, colour,
     lists, alignment, links, pictures, a film, a Book-a-demo button and {first_name}. The server keeps it to the email
     allowlist (App\Support\CampaignRich) and draws it in the layout's own type. Returns the editing box itself, with
     .value / .disabled like a textarea and .wrap (toolbar + box) to place on the page. */
  function plainToHtml(s) {
    s = String(s || '');
    if (/<[a-z][^>]*>/i.test(s)) return s;
    return s.split(/\n\s*\n/).map(function (p) { p = p.trim(); return p ? '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>' : ''; }).join('');
  }
  function rteCss() {
    if (document.getElementById('kc-rte-css')) return;
    var s = document.createElement('style'); s.id = 'kc-rte-css';
    s.textContent = [
      '.kc-rte{border:1.5px solid #E2E8F0;border-radius:10px;background:#fff;overflow:hidden}',
      '.kc-rte:focus-within{border-color:#0E8F99;box-shadow:0 0 0 3px rgba(14,143,153,.12)}',
      '.kc-rte-bar{display:flex;flex-wrap:nowrap;gap:2px;align-items:center;padding:4px 6px;border-bottom:1px solid #E2E8F0;background:#F8FAFC;overflow-x:auto;overflow-y:hidden;scrollbar-width:thin}',
      '.kc-rte-bar::-webkit-scrollbar{height:4px}.kc-rte-bar::-webkit-scrollbar-thumb{background:#CBD5E1;border-radius:4px}',
      '#appMain .kc-rte .kc-rte-bar button.kc-rte-b,.kc-rte .kc-rte-bar button.kc-rte-b{flex:0 0 auto;height:28px!important;min-height:0!important;min-width:28px!important;padding:0 6px!important;margin:0!important;border:1px solid transparent;border-radius:7px;background:transparent;color:#1E293B;font:700 13px/1 inherit!important;cursor:pointer;white-space:nowrap;box-shadow:none!important}',
      '.kc-rte-b:hover{background:#fff;border-color:#CBD5E1}.kc-rte-b[disabled]{opacity:.45;cursor:not-allowed}',
      '#appMain .kc-rte .kc-rte-bar .kc-rte-sel,.kc-f .kc-rte-sel,.kc-rte-sel{flex:0 0 auto;width:auto!important;max-width:112px!important;text-overflow:ellipsis;height:28px!important;min-height:0!important;padding:0 4px!important;border:1px solid #CBD5E1!important;border-radius:7px!important;font:600 12.5px/1 inherit!important;background:#fff;color:#1E293B}',
      '#appMain .kc-rte .kc-rte-bar .kc-rte-col,.kc-f .kc-rte-col,.kc-rte-col{flex:0 0 auto;width:28px!important;min-width:0!important;height:28px!important;min-height:0!important;padding:2px!important;border:1px solid #CBD5E1!important;border-radius:7px!important;background:#fff;cursor:pointer}',
      '@media (pointer:coarse){#appMain .kc-rte .kc-rte-bar button.kc-rte-b,.kc-rte .kc-rte-bar button.kc-rte-b,#appMain .kc-rte .kc-rte-bar .kc-rte-sel,.kc-rte .kc-rte-bar .kc-rte-sel,#appMain .kc-rte .kc-rte-bar .kc-rte-col,.kc-rte .kc-rte-bar .kc-rte-col{height:32px!important;min-height:32px!important}#appMain .kc-rte .kc-rte-bar button.kc-rte-b,.kc-rte .kc-rte-bar button.kc-rte-b{min-width:32px!important}}',
      '.kc-rte-sep{flex:0 0 auto;width:1px;height:18px;background:#CBD5E1;margin:0 3px}',
      '.kc-rte-ed{min-height:200px;max-height:520px;overflow:auto;padding:12px 14px;font:400 14.5px/1.6 Segoe UI,Helvetica,Arial,sans-serif;color:#1E293B;outline:none;resize:vertical}',
      '.kc-rte-ed p{margin:0 0 10px}.kc-rte-ed h2{font-size:19px;margin:6px 0 8px;color:#0B1F4D}.kc-rte-ed h3{font-size:16px;margin:4px 0 6px;color:#0B1F4D}',
      '.kc-rte-ed a{color:#0E8F99;font-weight:700}.kc-rte-ed img{max-width:100%;height:auto;border-radius:8px}.kc-rte-ed ul,.kc-rte-ed ol{margin:0 0 10px;padding-left:22px}',
      '.kc-rte-ed:empty:before{content:attr(data-ph);color:#94A3B8}',
      '.kc-rte.off{background:#F8FAFC}.kc-rte.off .kc-rte-ed{color:#64748B}',
    ].join('');
    document.head.appendChild(s);
  }
  function richBox(html, placeholder) {
    rteCss();
    var ed = el('div', { class: 'kc-rte-ed', contenteditable: 'true', spellcheck: 'true', role: 'textbox', 'aria-multiline': 'true', 'data-ph': placeholder || '' });
    ed.innerHTML = plainToHtml(html);
    var bar = el('div', { class: 'kc-rte-bar', role: 'toolbar', 'aria-label': 'Formatting', 'data-kt-nofb': '1' });   // not a phone filter bar
    var range = null;
    function keep() { var s = window.getSelection(); if (s && s.rangeCount && ed.contains(s.getRangeAt(0).commonAncestorContainer)) range = s.getRangeAt(0).cloneRange(); }
    function restore() { ed.focus(); if (range) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(range); } }
    function changed() { keep(); ed.dispatchEvent(new Event('input', { bubbles: true })); }
    function exec(cmd, val) { restore(); try { document.execCommand('styleWithCSS', false, cmd === 'foreColor'); } catch (e) {} document.execCommand(cmd, false, val == null ? null : val); changed(); }
    /* A block (picture, film, button, line) goes in as its own paragraph after the one being written in: insertHTML
       would merge it into that paragraph and copy the page's computed fonts onto it. */
    function insertBlock(h) {
      restore();
      var s = window.getSelection(), at = s && s.rangeCount ? s.getRangeAt(0).endContainer : null;
      while (at && at !== ed && at.parentNode !== ed) at = at.parentNode;
      var tmp = document.createElement('div'); tmp.innerHTML = h;
      var last = null, frag = document.createDocumentFragment();
      while (tmp.firstChild) { last = tmp.firstChild; frag.appendChild(tmp.firstChild); }
      if (at && at !== ed && at.parentNode === ed) ed.insertBefore(frag, at.nextSibling); else ed.appendChild(frag);
      if (last) { var rg = document.createRange(); rg.selectNodeContents(last); rg.collapse(false); s.removeAllRanges(); s.addRange(rg); }
      changed();
    }
    function insertText(t) { restore(); document.execCommand('insertText', false, t); changed(); }
    function insertLink(url, text) {
      restore();
      var s = window.getSelection(); if (!s.rangeCount) return;
      var a = document.createElement('a'); a.href = url; a.textContent = text;
      var rg = s.getRangeAt(0); rg.deleteContents(); rg.insertNode(a); rg.setStartAfter(a); rg.collapse(true); s.removeAllRanges(); s.addRange(rg);
      changed();
    }
    ['keyup', 'mouseup', 'focus'].forEach(function (ev) { ed.addEventListener(ev, keep); });
    function btn(label, tip, fn, style) {
      var b = el('button', { type: 'button', class: 'kc-rte-b', 'data-kttip': tip, 'aria-label': tip, style: style || null }, [label]);
      b.dataset.ktIconized = '1';
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', fn); bar.appendChild(b); return b;
    }
    function sep() { bar.appendChild(el('span', { class: 'kc-rte-sep' })); }
    var blk = el('select', { class: 'kc-rte-sel', 'aria-label': 'Text style', 'data-kttip': 'Text style' });
    [['', 'Text style'], ['p', 'Paragraph'], ['h2', 'Heading'], ['h3', 'Subheading']].forEach(function (o) { blk.appendChild(el('option', { value: o[0] }, [o[1]])); });
    blk.addEventListener('mousedown', keep);
    blk.addEventListener('change', function () { if (blk.value) exec('formatBlock', '<' + blk.value + '>'); blk.value = ''; });
    bar.appendChild(blk);
    btn('B', 'Bold', function () { exec('bold'); }, 'font-weight:900');
    btn('I', 'Italic', function () { exec('italic'); }, 'font-style:italic;font-family:Georgia,serif');
    btn('U', 'Underline', function () { exec('underline'); }, 'text-decoration:underline');
    btn('S', 'Strikethrough', function () { exec('strikeThrough'); }, 'text-decoration:line-through');
    var col = el('input', { type: 'color', class: 'kc-rte-col', value: '#0E8F99', 'aria-label': 'Text colour', 'data-kttip': 'Text colour' });
    col.addEventListener('mousedown', keep);
    col.addEventListener('change', function () { exec('foreColor', col.value); });
    bar.appendChild(col);
    sep();
    btn('•', 'Bulleted list', function () { exec('insertUnorderedList'); }, 'font-size:18px');
    btn('1.', 'Numbered list', function () { exec('insertOrderedList'); });
    btn('⇤', 'Align left', function () { exec('justifyLeft'); });
    btn('↔', 'Centre', function () { exec('justifyCenter'); });
    sep();
    btn('🔗', 'Link the selected words', async function () {
      var saved = range;
      var url = KT.prompt ? await KT.prompt({ title: 'Add a link', description: 'The selected words become a link to this page. Clicks are tracked like the rest of the email.', placeholder: 'https://…', type: 'url', okLabel: 'Add link' })
        : window.prompt('Link address (https://…)', 'https://');
      range = saved;
      url = String(url || '').trim(); if (!url) return;
      if (!/^(https?:|mailto:|tel:)/i.test(url)) url = 'https://' + url.replace(/^\/+/, '');
      var s = range ? range.toString() : '';
      if (s) exec('createLink', url); else insertLink(url, url.replace(/^https?:\/\//, ''));
    });
    btn('⛓', 'Remove the link', function () { exec('unlink'); });
    var file = el('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', style: 'display:none', 'data-kt-noface': '1' });
    var pic = btn('🖼', 'Add a picture (up to 5 MB)', function () { keep(); file.click(); });
    file.addEventListener('change', async function () {
      var f = file.files && file.files[0]; file.value = ''; if (!f) return;
      var fd = new FormData(); fd.append('image', f);
      pic.disabled = true; pic.textContent = '⏳';
      try {
        var r = await Api.postForm('/sales/campaign-images', fd);
        insertBlock('<p><img src="' + esc(r.url) + '" alt="" width="600" style="width:100%;max-width:100%;height:auto;border-radius:12px"></p>');
      } catch (e) { toast('⚠️', 'The picture was not added', e.message || '', '#B45309'); }
      pic.disabled = false; pic.textContent = '🖼';
    });
    bar.appendChild(file);
    var film = el('select', { class: 'kc-rte-sel', 'aria-label': 'Add a film', 'data-kttip': 'Add a film: its poster with a play button, opening the film page' });
    film.appendChild(el('option', { value: '' }, ['🎬 Film']));
    ((OPTS && OPTS.film_links) || []).forEach(function (v) { film.appendChild(el('option', { value: v.key }, [v.label])); });
    film.addEventListener('mousedown', keep);
    film.addEventListener('change', function () {
      var v = ((OPTS && OPTS.film_links) || []).filter(function (x) { return x.key === film.value; })[0]; film.value = '';
      if (v) insertBlock('<p><a href="' + esc(v.url) + '"><img src="' + esc(v.poster) + '" alt="▶ Watch ' + esc(v.label) + '" width="528" style="width:100%;max-width:528px;height:auto;border-radius:14px"></a></p><p>▶ <a href="' + esc(v.url) + '">Watch ' + esc(v.label) + '</a></p>');
    });
    bar.appendChild(film);
    btn('📅 Demo', 'Add a Book-a-demo button', function () {
      insertBlock('<p style="text-align:center"><a href="https://www.kiddietrac.com/?book=1" style="display:inline-block;padding:13px 26px;border-radius:12px;background:#0E8F99;color:#ffffff;font-weight:800;text-decoration:none">📅 Book a 20-minute demo</a></p><p><br></p>');
    });
    btn('👤 Name', 'Put each person\'s first name here', function () { insertText('{first_name}'); });
    btn('―', 'Dividing line', function () { insertBlock('<hr><p><br></p>'); });
    sep();
    btn('🧹', 'Clear formatting', function () { exec('removeFormat'); });
    btn('↶', 'Undo', function () { exec('undo'); });
    btn('↷', 'Redo', function () { exec('redo'); });
    var wrap = el('div', { class: 'kc-rte' }, [bar, ed]);
    Object.defineProperty(ed, 'value', {
      get: function () { var t = (ed.textContent || '').replace(/ /g, ' ').trim(); return t || ed.querySelector('img') ? ed.innerHTML : ''; },
      set: function (v) { ed.innerHTML = plainToHtml(v); },
    });
    Object.defineProperty(ed, 'disabled', {
      get: function () { return ed.getAttribute('contenteditable') === 'false'; },
      set: function (v) { ed.setAttribute('contenteditable', v ? 'false' : 'true'); wrap.classList.toggle('off', !!v); [].forEach.call(bar.querySelectorAll('button,select,input'), function (x) { x.disabled = !!v; }); },
    });
    ed.wrap = wrap;
    return ed;
  }
  /* A field holding the rich box: a <div>, not a <label> - a click inside a label would press its first button. */
  function richField(label, box, hint) {
    var lab = el('span', {}, [label]), sm = el('small', {}, [hint || '']);
    var d = el('div', { class: 'kc-f' }, [lab, sm, box.wrap]);
    d.setLabel = function (l, h) { lab.textContent = l; sm.textContent = h || ''; };
    return d;
  }
  /* THE DESIGN PANEL (2026-11-05, Anthony: "have it where it doesn't use the top header and footer as optional where I
     can create my own campaign from scratch with uploading images, content and headers/footers etc - totally
     customizable"). Only for "Your own email": header (KiddieTrac / none / your own), footer (KiddieTrac / your own /
     the legal line only), signature, colours, font, width, corners, spacing. Server: CampaignRich::canvas. */
  function canvasPanel(cv, onChange, locked) {
    var d = {}, k;
    var def = (OPTS && OPTS.canvas_default) || { header: 'kt', footer: 'kt', signature: true, page_bg: '#EEF2F7', sheet_bg: '#FFFFFF', text: '#1E293B',
      heading: '#0B1F4D', link: '#0E8F99', font: 'sans', width: 600, corners: true, pad: 'normal' };   // CampaignRich::canvas(null)
    for (k in def) d[k] = def[k];
    for (k in (cv || {})) if (cv[k] != null) d[k] = cv[k];
    var field = function (label, input, hint) { return el('label', { class: 'kc-f' }, [label, hint ? el('small', {}, [hint]) : null, input]); };
    var sel = function (opts, val) { var x = el('select'); opts.forEach(function (o) { var op = el('option', { value: String(o[0]) }, [o[1]]); if (String(o[0]) === String(val)) op.selected = true; x.appendChild(op); }); return x; };
    var header = sel([['kt', 'KiddieTrac logo band'], ['own', 'My own header (pictures, logo, words)'], ['none', 'No header']], d.header);
    var headBox = richBox(d.header_html || '', 'Your header: add a picture (your logo or a banner), words, links.');
    var headRow = richField('Your header', headBox, 'Only pictures = edge to edge (a banner). With words it lines up with the email.');
    var footer = sel([['kt', 'KiddieTrac footer (why they get it, address, unsubscribe, social links)'], ['own', 'My own footer + the legal line'], ['min', 'Only the legal line']], d.footer);
    var footBox = richBox(d.footer_html || '', 'Your footer: your name, address, links, a picture.');
    var footRow = richField('Your footer', footBox, '');
    var legal = el('div', { class: 'kc-cv-note' }, ['Every footer keeps one small line: KiddieTrac, our mailing address and an unsubscribe link. Canada’s anti-spam law (CASL) requires them in every sales email, so this line can’t be turned off.']);
    // The portal's switch row (payroll settings, agency settings): the switch, a 14px title, a grey hint.
    var chk = function (label, hint, on) { var c = el('input', { type: 'checkbox', 'data-kt-switch': '1', style: 'margin-top:2px' }); c.checked = !!on;
      return { box: c, el: el('label', { class: 'kc-cv-chk' }, [c, el('span', {}, [el('b', {}, [label]), el('small', {}, [hint])])]) }; };
    var sign = chk('Signature', 'Talk soon, your name, email and phone at the end.', d.signature), corners = chk('Rounded corners', 'Soft corners on the email.', d.corners);
    headBox.wrap.classList.add('kc-rte-sm'); footBox.wrap.classList.add('kc-rte-sm');
    var col = function (label, val) { var c = el('input', { type: 'color', value: val || '#FFFFFF', 'aria-label': label }); return { input: c, el: el('label', { class: 'kc-cv-col' }, [c, el('span', {}, [label])]) }; };
    var cols = { page_bg: col('Background', d.page_bg), sheet_bg: col('Email', d.sheet_bg), text: col('Text', d.text), heading: col('Headings', d.heading), link: col('Links', d.link) };
    var font = sel(((OPTS && OPTS.fonts) || [{ key: 'sans', label: 'Clean' }]).map(function (f) { return [f.key, String(f.label).replace(/\s*\(.*\)\s*$/, '')]; }), d.font);
    var width = sel([[560, 'Narrow'], [600, 'Standard'], [680, 'Wide']], d.width);
    font.setAttribute('data-kttip', 'Clean = Segoe UI · Modern = Verdana · Classic = Georgia · Typewriter = Courier');
    width.setAttribute('data-kttip', 'Narrow 560px · Standard 600px · Wide 680px (phones always fit the screen)');
    var pad = sel([['normal', 'Roomy'], ['tight', 'Tight']], d.pad);
    var reset = el('button', { type: 'button', class: 'kc-btn', style: 'height:28px;padding:0 10px;font-size:12px', onclick: function () {
      for (var k2 in cols) cols[k2].input.value = def[k2];
      font.value = def.font; width.value = String(def.width); pad.value = def.pad; corners.box.checked = !!def.corners; changed();
    } }, ['↺ Default look']); reset.dataset.ktIconized = '1';
    function sync() { headRow.style.display = header.value === 'own' ? '' : 'none'; footRow.style.display = footer.value === 'own' ? '' : 'none'; }
    function changed() { sync(); if (onChange) onChange(); }
    [header, footer, font, width, pad, sign.box, corners.box].forEach(function (x) { x.addEventListener('change', changed); });
    for (k in cols) cols[k].input.addEventListener('input', changed);
    [headBox, footBox].forEach(function (b) { b.addEventListener('input', function () { if (onChange) onChange(); }); });
    var title = el('span', {}, ['🎨 Design: header, footer and look']);
    var full = el('div', {}, [field('Header', header), headRow, field('Footer', footer), footRow, legal]);
    var look = el('div', {}, [
      el('div', { class: 'kc-lbl' }, ['Colours']),
      el('div', { class: 'kc-cv-grid' }, [cols.page_bg.el, cols.sheet_bg.el, cols.text.el, cols.heading.el, cols.link.el]),
      el('div', { class: 'kc-cv-grid' }, [field('Font', font), field('Width', width), field('Spacing', pad)])]);
    var wrap = el('div', { class: 'kc-cv', 'data-kt-nofb': '1' }, [
      el('div', { class: 'kc-cv-h' }, [title, reset]), full,
      el('div', { class: 'kc-cv-grid two' }, [sign.el, corners.el]), look,
    ]);
    // 'full' = "Your own email"; 'sign' = the standard email and the designs (only the signature applies); 'none'.
    wrap._ktMode = function (m) {
      wrap.style.display = m === 'none' ? 'none' : '';
      var f2 = m === 'full'; full.style.display = look.style.display = corners.el.style.display = reset.style.display = f2 ? '' : 'none';
      title.textContent = f2 ? '🎨 Design: header, footer and look' : '✍️ Sign-off';
    };
    if (locked) [header, footer, font, width, pad, sign.box, corners.box].concat(Object.keys(cols).map(function (k3) { return cols[k3].input; })).forEach(function (x) { x.disabled = true; });
    if (locked) { headBox.disabled = true; footBox.disabled = true; }
    sync();
    return { el: wrap, value: function () {
      return { header: header.value, header_html: headBox.value || null, footer: footer.value, footer_html: footBox.value || null,
        signature: sign.box.checked, corners: corners.box.checked, page_bg: cols.page_bg.input.value, sheet_bg: cols.sheet_bg.input.value, text: cols.text.input.value,
        heading: cols.heading.input.value, link: cols.link.input.value, font: font.value, width: +width.value, pad: pad.value };
    } };
  }

  /* "Your own email" shows only what was written: no film, no highlight cards, no automatic greeting. */
  function layoutRows(layout, introRow, others, box) {
    var custom = layout === 'custom', standard = layout === 'standard';
    introRow.setLabel(custom ? 'Email body' : 'Introduction', custom
      ? 'The whole email. 👤 Name puts each person\'s first name where you want it. Header, footer, signature and colours are in 🎨 Design below.'
      : 'Each person is greeted by first name above this. Leave it empty for our standard introduction.');
    others.film.style.display = custom ? 'none' : '';
    others.hl.style.display = standard ? '' : 'none';
    if (others.canvas) others.canvas._ktMode(custom ? 'full' : /^sub:/.test(layout) ? 'none' : 'sign');
    if (custom && !box.value) { box.value = '<p>Hi {first_name},</p><p><br></p>'; }
  }

  /* ───────────────────────────── email templates ─────────────────────────────
     2026-11-04, Anthony: "how do we create our own campaign with the campaign editor vs using one of the preset
     templates which I cannot seem to see ... there should be a drop down on preset campaign templates and ability to
     add a new one and edit existing etc". The bar on the Email tab picks a template (KiddieTrac presets, then the
     team's own) or leaves the email as your own words; Save as template keeps the current email for next time;
     Manage templates adds, edits and removes them. Applying a template copies it: the campaign is then edited freely
     and later template edits never change it. */
  function templateBar(c, reload, sched, current) {
    var locked = c.status === 'sending' || c.status === 'sent';
    var tpls = (OPTS && OPTS.email_templates) || [];
    var sel = el('select', { 'aria-label': 'Email template', style: 'flex:1;min-width:220px;max-width:420px' });
    sel.appendChild(el('option', { value: '' }, [c.template_id ? 'Choose a template…' : '✍️ Your own email (not from a template)']));
    var group = function (label, rows) {
      if (!rows.length) return;
      var g = el('optgroup', { label: label });
      rows.forEach(function (t) { var o = el('option', { value: String(t.id) }, [t.name]); if (t.id === c.template_id) o.selected = true; g.appendChild(o); });
      sel.appendChild(g);
    };
    group('KiddieTrac presets', tpls.filter(function (t) { return t.kind === 'preset'; }));
    group('Your templates', tpls.filter(function (t) { return t.kind !== 'preset'; }));
    if (locked) sel.disabled = true;
    sel.addEventListener('change', async function () {
      var t = tpls.filter(function (x) { return String(x.id) === sel.value; })[0];
      if (!t) return;
      var q = 'Use the template "' + t.name + '"?';
      var ok = KT.confirm ? await KT.confirm(q, { description: 'It replaces this campaign’s subject and words with the template’s. The people on the campaign stay on it.'
        + (/^sub:/.test(t.layout) ? ' This template is a finished KiddieTrac design: only its subject can be changed.' : '') }) : window.confirm(q);
      if (!ok) { sel.value = c.template_id ? String(c.template_id) : ''; return; }
      sel.disabled = true;
      try { await Api.post('/sales/campaigns/' + c.id + '/apply-template', { template_id: t.id }); toast('✓', 'Template applied', t.name, '#16A34A'); reload(); }
      catch (e) { sel.disabled = false; sel.value = c.template_id ? String(c.template_id) : ''; toast('⚠️', 'Could not apply it', e.message, '#DC2626'); }
    });
    var saveAs = iconized(el('button', { class: 'kc-btn', 'data-kt-always-save': '1', onclick: function () { saveAsTemplate(c, current ? current() : {}); } }, ['💾 Save as template']));
    var manage = iconized(el('button', { class: 'kc-btn', onclick: function () { manageTemplates(reload); } }, ['🗂 Manage templates']));
    var whenTxt = c.status === 'sent' ? 'Sent ' + when(c.sent_at) : c.status === 'sending' ? 'Sending now'
      : c.scheduled_label ? '🗓 ' + c.scheduled_label + (c.approved_at ? ' · approved' : ' · needs approval') : 'Not scheduled yet';
    var schedBtn = !locked && sched ? iconized(el('button', { class: 'kc-btn', onclick: sched }, [c.scheduled_for ? '🗓 Change time' : '🗓 Schedule'])) : null;
    return el('div', { class: 'kc-card kc-tplbar' }, [el('div', { class: 'kc-row', style: 'justify-content:space-between;align-items:flex-start;gap:18px' }, [
      el('div', { style: 'flex:1;min-width:280px' }, [
        el('div', { class: 'kc-lbl' }, ['Email template']),
        el('div', { class: 'kc-row' }, [sel, saveAs, manage]),
        el('div', { class: 'kc-sub', style: 'margin-top:6px' }, [locked ? 'This campaign has ' + (c.status === 'sent' ? 'been sent' : 'started sending') + ', so its email is fixed. You can still save it as a template.'
          : 'Pick a KiddieTrac preset or one of your templates to fill the email in, or write your own below. Everything stays editable; Save as template keeps it for your next campaign.']),
      ]),
      el('div', { style: 'min-width:220px' }, [el('div', { class: 'kc-lbl' }, ['When it sends']),
        el('div', { class: 'kc-row' }, [el('span', { style: 'font-weight:700;color:#334155;font-size:13.5px' }, [whenTxt]), schedBtn])]),
    ])]);
  }

  async function refreshTemplates() {
    try { var d = await Api.get('/sales/campaign-templates'); if (!OPTS) OPTS = {}; OPTS.email_templates = d.templates || []; if (d.layouts) OPTS.layouts = d.layouts; } catch (e) {}
    return (OPTS && OPTS.email_templates) || [];
  }

  /* The email as it stands on screen (unsaved edits included) becomes a new template. */
  function saveAsTemplate(c, vals) {
    var name = el('input', { value: c.name });
    var status = el('div', { style: 'color:#B91C1C;font-size:12.5px;min-height:16px;margin:4px 0 8px' });
    var b = el('button', { class: 'kc-btn p', 'data-kt-always-save': '1', onclick: async function () {
      if (!name.value.trim()) { status.textContent = 'Give the template a name.'; name.focus(); return; }
      b.disabled = true;
      var body = { name: name.value.trim(), layout: vals.layout || c.layout || 'standard', subject: vals.subject || c.subject,
        preheader: vals.preheader != null ? vals.preheader : c.preheader, headline: vals.headline != null ? vals.headline : c.headline,
        intro: vals.intro != null ? vals.intro : c.intro, video: vals.video || c.video, highlights: vals.highlights || c.highlights, show_plans: !!c.show_plans,
        canvas: vals.canvas || c.canvas || null };
      try { var r = await Api.post('/sales/campaign-templates', body); OPTS.email_templates = r.templates; closeModal(); toast('💾', 'Saved as a template', body.name + ' is in the template list for your next campaign.', '#16A34A'); }
      catch (e) { b.disabled = false; status.textContent = e.message || 'Could not save it.'; }
    } }, ['💾 Save template']); iconized(b);
    modal('💾 Save as template', el('div', {}, [
      el('label', { class: 'kc-f' }, ['Template name', el('small', {}, ['Your team picks it by this name on any campaign.']), name]),
      el('p', { class: 'kc-sub', style: 'margin:0 0 6px' }, ['Saves this email as it is on screen now: its layout, subject and words. The people on the campaign are not part of it.']),
      status, b,
    ]));
    setTimeout(function () { try { name.focus(); name.select(); } catch (e) {} }, 60);
  }

  async function manageTemplates(after) {
    var body = el('div', {}, [el('div', { class: 'kc-sub' }, ['Loading templates…'])]);
    modal('🗂 Email templates', body, true);
    var list = await refreshTemplates();
    body.innerHTML = '';
    body.appendChild(el('div', { class: 'kc-row', style: 'justify-content:space-between;margin-bottom:10px' }, [
      el('div', { class: 'kc-sub', style: 'max-width:600px' }, ['KiddieTrac presets can be edited but not removed; your own templates can be edited and removed. Changing a template never changes a campaign already made from it.']),
      iconized(el('button', { class: 'kc-btn p', onclick: function () { editTemplate(null, after); } }, ['➕ New template'])),
    ]));
    var tb = el('tbody');
    list.forEach(function (t) {
      var acts = el('td', { style: 'text-align:right;white-space:nowrap' }, [
        el('button', { class: 'kc-btn', title: '✏️ Edit', onclick: function (ev) { ev.stopPropagation(); editTemplate(t, after); } }, ['✏️ Edit']),
        t.kind !== 'preset' ? el('button', { class: 'kc-btn', title: '🗑 Remove', style: 'margin-left:6px', onclick: async function (ev) {
          ev.stopPropagation();
          var q = 'Remove the template "' + t.name + '"?';
          var ok = KT.confirm ? await KT.confirm(q, { description: 'Campaigns already made from it keep their email.' }) : window.confirm(q);
          if (!ok) return;
          try { var r = await Api.delete('/sales/campaign-templates/' + t.id); OPTS.email_templates = r.templates; toast('🗑', 'Template removed', t.name); manageTemplates(after); if (after) after(); }
          catch (e) { toast('⚠️', 'Could not remove it', e.message, '#DC2626'); }
        } }, ['🗑 Remove']) : null,
      ]);
      tb.appendChild(el('tr', { 'data-tpl': String(t.id), onclick: function () { editTemplate(t, after); } }, [
        el('td', {}, [el('div', { style: 'font-weight:700;color:#0F172A' }, [t.name]), el('div', { class: 'kc-sub', style: 'max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis' }, [t.subject])]),
        el('td', {}, [t.layout_label]),
        el('td', {}, [t.kind === 'preset' ? el('span', { class: 'kc-pill', style: 'background:#E0F2FE;color:#075985' }, ['KiddieTrac preset']) : el('span', { class: 'kc-pill', style: 'background:#EDE9FE;color:#5B21B6' }, ['Your template'])]),
        el('td', { class: 'kc-sub' }, [when(t.updated_at) + (t.updated_by ? ' · ' + t.updated_by : '')]),
        acts,
      ]));
    });
    body.appendChild(el('div', { style: 'overflow-x:auto' }, [el('table', { class: 'kc-tbl kc-tpl-tbl' }, [el('thead', {}, [el('tr', {}, ['Template', 'Layout', 'Type', 'Last edited', ''].map(function (h) { return el('th', {}, [h]); }))]), tb])]));
  }

  /* Add or edit one template, with the email drawn beside it as it will arrive. */
  function editTemplate(t, after) {
    var isNew = !t;
    t = t || { name: '', layout: 'custom', subject: 'A one-minute look at KiddieTrac', preheader: '', headline: '', intro: '', video: 'a-day-with-kiddietrac',
      highlights: (OPTS && OPTS.default_highlights) || ['billing', 'parentapp', 'compliance', 'staff'], kind: 'custom' };
    var field = function (label, input, hint) { return el('label', { class: 'kc-f' }, [label, hint ? el('small', {}, [hint]) : null, input]); };
    var f = {};
    f.name = el('input', { value: t.name || '', placeholder: 'e.g. Spring open house invitation' });
    f.layout = layoutSelect(t.layout, true);
    f.subject = el('input', { value: t.subject || '' });
    f.preheader = el('input', { value: t.preheader || '', placeholder: 'The grey line inboxes show after the subject' });
    f.headline = el('input', { value: t.headline || '' });
    f.intro = richBox(t.intro || '', 'Leave empty for the standard introduction.');
    f.video = el('select'); ((OPTS && OPTS.videos) || []).forEach(function (v) { var o = el('option', { value: v.key }, [v.label]); if (v.key === t.video) o.selected = true; f.video.appendChild(o); });
    var hl = el('div', { class: 'kc-hl' });
    ((OPTS && OPTS.highlights) || []).forEach(function (h) {
      var cb = el('input', { type: 'checkbox', value: h.key }); cb.checked = (t.highlights || []).indexOf(h.key) > -1;
      var lab = el('label', { class: cb.checked ? 'on' : '' }, [cb, el('span', {}, [h.icon + ' ' + h.label])]);
      cb.addEventListener('change', function () { lab.classList.toggle('on', cb.checked); schedulePreview(); });
      hl.appendChild(lab);
    });
    var cvp = canvasPanel(t.canvas, function () { schedulePreview(); }, false);
    var introRow = richField('Introduction', f.intro), rows = {
      canvas: cvp.el,
      film: field('Film', f.video), hl: el('div', { class: 'kc-f' }, ['Also highlight', el('small', {}, ['Shown by the standard introduction. Four reads best. Prices are never in the email.']), hl]) };
    var words = el('div', {}, [field('Preview line', f.preheader), field('Headline', f.headline, 'Optional in "Your own email".'), introRow, rows.canvas, rows.film, rows.hl]);
    var subNote = el('div', { style: 'background:#F5F3FF;border:1px solid #E4DDFB;border-radius:12px;padding:10px 12px;margin-bottom:12px;font-size:13px;color:#5B21B6' },
      [el('b', {}, ['A finished KiddieTrac design: only its subject changes, and it goes to the subscriber list. ']),
       'To write your own words (a holiday greeting, an announcement), pick “Your own email” under Layout.']);
    var vals = function () {
      return { name: f.name.value.trim(), layout: f.layout.value, subject: f.subject.value.trim(), preheader: f.preheader.value.trim() || null, headline: f.headline.value.trim() || null,
        intro: f.intro.value.trim() || null, video: f.video.value || null, highlights: [].map.call(hl.querySelectorAll('input:checked'), function (i) { return i.value; }),
        canvas: cvp.value() };
    };
    var sync = function () { var sub = /^sub:/.test(f.layout.value); words.style.display = sub ? 'none' : ''; subNote.style.display = sub ? '' : 'none'; layoutRows(f.layout.value, introRow, rows, f.intro); };
    var frame = el('iframe', { title: 'Template preview' });
    var pvTimer = null;
    function schedulePreview() { clearTimeout(pvTimer); pvTimer = setTimeout(drawPreview, 500); }
    function drawPreview() {
      var v = vals(); if (!v.subject) v.subject = 'Preview'; delete v.name;
      fetch(apiBase() + '/sales/campaign-templates/preview', { method: 'POST', headers: { Authorization: 'Bearer ' + tok(), Accept: 'text/html', 'Content-Type': 'application/json' }, body: JSON.stringify(v) })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); }).then(function (html) { frame.srcdoc = html; })
        .catch(function () { frame.srcdoc = '<p style="font-family:sans-serif;padding:20px;color:#64748B">Preview unavailable.</p>'; });
    }
    Object.keys(f).forEach(function (k) { f[k].addEventListener(f[k].tagName === 'SELECT' ? 'change' : 'input', function () { if (k === 'layout') sync(); if (k !== 'name') schedulePreview(); }); });
    var status = el('div', { style: 'color:#B91C1C;font-size:12.5px;min-height:16px;margin:4px 0 8px' });
    var save = el('button', { class: 'kc-btn p', 'data-kt-always-save': '1', onclick: async function () {
      var v = vals();
      if (!v.name) { status.textContent = 'Give the template a name.'; f.name.focus(); return; }
      if (!v.subject) { status.textContent = 'Write the subject line.'; f.subject.focus(); return; }
      if (v.layout === 'standard' && !v.highlights.length) { status.textContent = 'Pick at least one thing to highlight.'; return; }
      save.disabled = true;
      try {
        var r = isNew ? await Api.post('/sales/campaign-templates', v) : await Api.patch('/sales/campaign-templates/' + t.id, v);
        OPTS.email_templates = r.templates; closeModal(); toast('✓', isNew ? 'Template added' : 'Template saved', v.name, '#16A34A');
        if (after) after();
        manageTemplates(after);
      } catch (e) { save.disabled = false; status.textContent = e.message || 'Could not save it.'; }
    } }, [isNew ? '➕ Add template' : '💾 Save template']); iconized(save);
    var back = el('button', { class: 'kc-btn', style: 'margin-left:8px', onclick: function () { closeModal(); manageTemplates(after); } }, ['Back to templates']);
    back.setAttribute('data-kt-inpage', '1');
    var left = el('div', {}, [
      t.kind === 'preset' ? el('div', { class: 'kc-sub', style: 'margin-bottom:10px' }, ['A KiddieTrac preset. Your changes are used by every new campaign that picks it.']) : null,
      field('Template name', f.name, 'Your team picks it by this name.'),
      field('Layout', f.layout, 'How the email looks. The words below fill it in.'),
      field('Subject', f.subject), subNote, words, status, el('div', { class: 'kc-row' }, [save, back]),
    ]);
    modal(isNew ? '➕ New email template' : '✏️ ' + t.name, el('div', { class: 'kc-tpl-edit' }, [left, el('div', {}, [el('div', { class: 'kc-lbl' }, ['Preview']), frame])]), true);
    sync(); drawPreview();
    setTimeout(function () { try { if (isNew) f.name.focus(); } catch (e) {} }, 60);
  }

  /* ───────────────────────────── one campaign ───────────────────────────── */
  var POLL = null;
  async function renderCampaign(main, ctx) {
    css(); main.innerHTML = '';
    if (POLL) { clearInterval(POLL); POLL = null; }
    var id = (ctx && ctx.params && ctx.params.id) || (String(window.location.hash).match(/[?&]id=(\d+)/) || [])[1];
    // data-kt-self-live (2026-11-05): kt-live redrew this screen after every POST - the live preview is one - and threw
    // away what was being written. The screen reloads itself after its own saves.
    var wrap = el('div', { class: 'kc-wrap', 'data-kt-screen': 'sales-campaign', 'data-kt-self-live': '1' }); main.appendChild(wrap);
    if (!id) { go('sales-campaigns'); return; }
    if (!OPTS) { try { OPTS = (await Api.get('/sales/campaigns')).options; } catch (e) {} }
    var d;
    try { d = await Api.get('/sales/campaigns/' + id); } catch (e) { wrap.appendChild(el('div', { class: 'kc-card', style: 'color:#B91C1C' }, ['Could not load the campaign: ' + (e.message || e)])); return; }
    var c = d.campaign, people = d.recipients || [];
    var tab = (String(window.location.hash).match(/[?&]tab=(\w+)/) || [])[1] || (c.counts.sent ? 'results' : 'email');

    // header: name, status, what to do next
    var head = el('div', { class: 'kc-row', style: 'justify-content:space-between;margin-bottom:6px' });
    head.appendChild(el('div', { class: 'kc-row' }, [
      el('button', { class: 'kc-btn', onclick: function () { go('sales-campaigns'); } }, ['← Campaigns']),
      el('b', { style: 'font-size:18px;color:' + NAVY }, [c.name]), pill(STATUS, st(c)),
      c.template ? el('span', { class: 'kc-pill', style: 'background:#EDE9FE;color:#5B21B6' }, ['📬 Subscriber email: ' + c.template_label]) : null,
      el('span', { class: 'kc-sub' }, [c.status === 'sending' ? 'Sending in small batches from sales@ · ' + c.queued + ' to go' : c.status === 'sent' ? 'Sent ' + when(c.sent_at) + (c.sent_by ? ' by ' + c.sent_by : '') : c.queued + ' waiting to receive it']),
    ]));
    var acts = el('div', { class: 'kc-row' });
    acts.appendChild(el('button', { class: 'kc-btn', onclick: function () { testSend(c); } }, ['✉️ Send me a test']));
    acts.appendChild(el('button', { class: 'kc-btn', title: 'A new draft with this email', onclick: function () { copyCampaign(c); } }, ['⧉ Copy']));
    if (c.status === 'sending') acts.appendChild(el('button', { class: 'kc-btn warn', onclick: async function () { try { await Api.post('/sales/campaigns/' + c.id + '/pause', {}); toast('⏸', 'Paused', 'Nobody else gets it until you send again.'); renderCampaign(main, ctx); } catch (e) { toast('⚠️', 'Could not pause', e.message, '#DC2626'); } } }, ['⏸ Pause']));
    else if (c.status !== 'sent' || c.queued) acts.appendChild(el('button', { class: 'kc-btn p', disabled: c.queued ? null : true, title: c.queued ? '' : 'Add people first', onclick: function () { sendCampaign(c, main, ctx); } }, [c.status === 'paused' ? '▶ Resume sending' : '📤 Send campaign']));
    if (c.status !== 'sending' && c.status !== 'sent') acts.appendChild(el('button', { class: 'kc-btn', onclick: function () { scheduleCampaign(c, main, ctx); } }, [c.scheduled_for ? '🗓 Change time' : '🗓 Schedule']));
    head.appendChild(acts);
    wrap.appendChild(head);
    /* APPROVE + SCHEDULE (2026-10-27): a scheduled campaign only goes once approved; changing the time asks again. */
    if (c.scheduled_for && c.status !== 'sending' && c.status !== 'sent') {
      var ok = !!c.approved_at;
      wrap.appendChild(el('div', { class: 'kc-card kc-row', style: 'justify-content:space-between;background:' + (ok ? '#F0FDF4' : '#FFFBEB') + ';border-color:' + (ok ? '#BBF7D0' : '#FDE68A') }, [
        el('div', {}, [el('b', { style: 'color:' + (ok ? '#166534' : '#92400E') }, [ok ? '✅ Approved — it goes out ' + c.scheduled_label : '⏳ Scheduled for ' + c.scheduled_label + ' — waiting for approval']),
          el('div', { class: 'kc-sub', style: 'margin-top:3px' }, [ok ? 'Approved' + (c.approved_by ? ' by ' + c.approved_by : '') + ' ' + when(c.approved_at) + '. ' + c.queued + ' people will receive it.'
            : 'Nothing is sent until it is approved. Send yourself a test and check the preview first. ' + c.queued + ' people are on the list.'])]),
        el('button', { class: 'kc-btn ' + (ok ? 'warn' : 'p'), onclick: async function () {
          try { await Api.post('/sales/campaigns/' + c.id + '/approve', { approve: !ok }); toast(ok ? '⏸' : '✅', ok ? 'Approval withdrawn' : 'Approved', ok ? 'It will not go out until approved again.' : 'It goes out ' + c.scheduled_label + '.', '#16A34A'); renderCampaign(main, ctx); }
          catch (e) { toast('⚠️', 'Could not do that', e.message, '#DC2626'); }
        } }, [ok ? 'Withdraw approval' : '✅ Approve to send']),
      ]));
    }

    wrap.appendChild(el('div', { class: 'kc-tiles' }, [
      tile(c.counts.recipients, 'People'), tile(c.counts.sent, 'Sent'),
      tile(c.counts.sent ? c.rates.opened + '%' : '—', 'Opened (' + c.counts.opened + ')'),
      tile(c.counts.sent ? c.rates.clicked + '%' : '—', 'Clicked (' + c.counts.clicked + ')'),
      tile(c.counts.responded, 'Responded'), tile(c.counts.unsubscribed, 'Unsubscribed'),
    ]));

    var tabs = el('div', { class: 'kc-tabs', role: 'tablist' });
    var pane = el('div');
    [['email', '✉️ Email'], ['people', '👥 People (' + c.counts.recipients + ')'], ['results', '📈 Results']].forEach(function (t) {
      tabs.appendChild(el('button', { class: 'kc-tab' + (t[0] === tab ? ' on' : ''), role: 'tab', 'aria-selected': t[0] === tab ? 'true' : 'false', onclick: function () {
        try { history.replaceState(null, '', '#sales-campaign?id=' + c.id + '&tab=' + t[0]); } catch (e) {}
        [].forEach.call(tabs.children, function (b) { b.classList.toggle('on', b === this); b.setAttribute('aria-selected', b === this ? 'true' : 'false'); }, this);
        show(t[0]);
      } }, [t[1]]));
    });
    wrap.appendChild(tabs); wrap.appendChild(pane);
    function show(k) {
      pane.innerHTML = '';
      if (k === 'email') drawEditor(pane, c, function () { renderCampaign(main, ctx); }, function () { scheduleCampaign(c, main, ctx); });
      else if (k === 'people') drawPeople(pane, c, people, function () { renderCampaign(main, ctx); });
      else drawResults(pane, c, people);
    }
    show(tab);
    if (c.status === 'sending') POLL = setInterval(function () { if (!document.body.contains(wrap)) { clearInterval(POLL); POLL = null; return; } if (!document.querySelector('.kt-modal-overlay,.modal-backdrop')) renderCampaign(main, ctx); }, 15000);
  }

  /* Send from (2026-11-09, Anthony: "with sales campaign add option to send from any mailbox that is setup on the portal
     (platform emails)"): every KiddieTrac mailbox connected in Sales > Email. Empty = the usual sender. */
  function fromSelect(c) {
    var usual = c.template ? 'Usual: KiddieTrac no-reply (replies to info@kiddietrac.com)' : 'Usual: KiddieTrac Sales <sales@kiddietrac.com>';
    var s = el('select', {}, [el('option', { value: '' }, [usual])]);
    var boxes = (OPTS && OPTS.mailboxes) || [], found = false;
    boxes.forEach(function (m) {
      var o = el('option', { value: String(m.id) }, [m.name + ' <' + m.email + '>']);
      if (c.from_account_id === m.id) { o.selected = true; found = true; }
      s.appendChild(o);
    });
    if (c.from_account_id && !found) {   // chosen earlier, since disconnected: shown so nobody thinks it is still fine
      var gone = el('option', { value: String(c.from_account_id) }, ['⚠ A mailbox that is no longer connected']); gone.selected = true; s.appendChild(gone);
    }
    return s;
  }
  var FROM_HINT = 'Any KiddieTrac mailbox connected in Sales › Email. Replies come back to it; if Microsoft will not send from it, that email is marked not sent with the reason.';

  function drawEditor(pane, c, reload, sched) {
    if (c.template) { drawTemplateEditor(pane, c, reload, sched); return; }
    var locked = c.status === 'sending';
    var f = {};
    var field = function (label, input, hint) { return el('label', { class: 'kc-f' }, [label, hint ? el('small', {}, [hint]) : null, input]); };
    f.name = el('input', { value: c.name }); f.subject = el('input', { value: c.subject });
    f.from = fromSelect(c);
    // The layout (2026-11-04): the standard introduction or one of the KiddieTrac designs.
    f.layout = layoutSelect(c.design || 'standard', false);
    f.preheader = el('input', { value: c.preheader || '', placeholder: 'A one-minute look at KiddieTrac, the Canadian childcare platform.' });
    f.headline = el('input', { value: c.headline || '', placeholder: 'Childcare software built for Canadian centres' });
    f.intro = richBox(c.intro || '', 'Leave empty for our standard introduction.');
    f.video = el('select'); (OPTS ? OPTS.videos : []).forEach(function (v) { var o = el('option', { value: v.key }, [v.label]); if (v.key === c.video) o.selected = true; f.video.appendChild(o); });
    f.consent = el('select'); (OPTS ? OPTS.consent : []).forEach(function (v) { var o = el('option', { value: v.key }, [v.label]); if (v.key === c.consent_basis) o.selected = true; f.consent.appendChild(o); });
    f.plans = el('input', { type: 'checkbox' }); f.plans.checked = !!c.show_plans;
    var hl = el('div', { class: 'kc-hl' }), chosen = (c.highlights || []).slice();
    (OPTS ? OPTS.highlights : []).forEach(function (h) {
      var cb = el('input', { type: 'checkbox', value: h.key }); cb.checked = chosen.indexOf(h.key) > -1;
      var lab = el('label', { class: cb.checked ? 'on' : '' }, [cb, el('span', {}, [h.icon + ' ' + h.label])]);
      cb.addEventListener('change', function () { lab.classList.toggle('on', cb.checked); });
      hl.appendChild(lab);
    });
    if (locked) Object.keys(f).forEach(function (k) { f[k].disabled = true; });
    var cvp = canvasPanel(c.canvas, function () { livePreview(); }, locked);
    var introRow = richField('Introduction', f.intro), rows = {
      canvas: cvp.el,
      film: field('Film', f.video, 'Shown as a poster with a play button; it opens the film page on kiddietrac.com.'),
      hl: el('div', { class: 'kc-f' }, ['Also highlight', el('small', {}, ['One-Tap, Safe Arrival and Walk Watch are always shown with their logos. These cards follow them; four reads best. Prices are never in the email.']), hl]) };
    layoutRows(f.layout.value, introRow, rows, f.intro);
    var status = el('div', { class: 'kc-sub', style: 'min-height:16px;margin:6px 0' });
    async function doSave(silent) {
      var picks = [].map.call(hl.querySelectorAll('input:checked'), function (i) { return i.value; });
      if (!picks.length && f.layout.value === 'standard') { status.textContent = 'Pick at least one thing to highlight.'; return false; }
      if (!picks.length) picks = (OPTS && OPTS.default_highlights) || ['billing'];
      save.disabled = true; status.textContent = 'Saving…';
      try {
        await Api.patch('/sales/campaigns/' + c.id, { name: f.name.value.trim(), subject: f.subject.value.trim(), preheader: f.preheader.value.trim() || null,
          headline: f.headline.value.trim() || null, intro: f.intro.value.trim() || null, video: f.video.value, consent_basis: f.consent.value,
          highlights: picks, design: f.layout.value, canvas: cvp.value(), from_account_id: f.from.value ? +f.from.value : null });
        DIRTY = null;
        if (!silent) { toast('✓', 'Saved', 'The preview shows the new version.', '#16A34A'); reload(); }
        else { save.disabled = false; status.textContent = 'Saved.'; }
        return true;
      } catch (e) { save.disabled = false; status.textContent = e.message || 'Could not save.'; return false; }
    }
    var save = el('button', { class: 'kc-btn p', 'data-kt-always-save': '1', disabled: locked ? true : null, onclick: function () { doSave(false); } }, ['💾 Save email']);
    // "Send me a test" saves what is on screen first (2026-11-05): the test is the email you are looking at.
    var testBtn = el('button', { class: 'kc-btn', style: 'margin-left:8px', onclick: function () { testSend(c); } }, ['✉️ Send me a test']);
    testBtn.dataset.ktIconized = '1';
    var left = el('div', { class: 'kc-card' }, [
      locked ? el('div', { style: 'background:#FEF3C7;border-radius:10px;padding:9px 12px;font-size:13px;color:#92400E;margin-bottom:12px' }, ['This campaign is sending. Pause it to change the email.']) : null,
      field('Campaign name', f.name, 'Only your team sees this.'),
      field('Layout', f.layout, 'How the email looks. The words below fill it in; the preview shows the result after you save.'),
      field('Send from', f.from, FROM_HINT),
      field('Subject', f.subject),
      field('Preview line', f.preheader, 'The grey text inboxes show after the subject.'),
      field('Headline', f.headline), introRow, rows.film, rows.hl,
      rows.canvas,
      field('Why they may receive it (Canada’s anti-spam law, CASL)', f.consent, 'Printed at the bottom of the email. Every email also carries our address and an unsubscribe link.'),
      status, el('div', { class: 'kc-row' }, [save, locked ? null : testBtn]),
    ]);
    DIRTY = null;
    if (!locked) {
      var dirty = function () { DIRTY = { id: c.id, el: left, save: function () { return doSave(true); } }; };
      left.addEventListener('input', dirty); left.addEventListener('change', dirty);
    }
    var frame = el('iframe', { title: 'Email preview' });
    var right = el('div', { class: 'kc-prev' }, [el('div', { class: 'kc-row', style: 'justify-content:space-between;padding:10px 14px;background:#fff;border-bottom:1px solid #E2E8F0' }, [
      el('b', { style: 'font-size:13px;color:#334155' }, ['Preview']), el('span', { class: 'kc-sub' }, ['As it arrives. Links here are not tracked.'])]), frame]);
    pane.appendChild(templateBar(c, reload, sched, function () {
      return { layout: f.layout.value, subject: f.subject.value.trim(), preheader: f.preheader.value.trim() || null, headline: f.headline.value.trim() || null,
        intro: f.intro.value.trim() || null, video: f.video.value, highlights: [].map.call(hl.querySelectorAll('input:checked'), function (i) { return i.value; }),
        canvas: cvp.value() };
    }));
    pane.appendChild(el('div', { class: 'kc-edit' }, [left, right]));
    fetch(apiBase() + '/sales/campaigns/' + c.id + '/preview', { headers: { Authorization: 'Bearer ' + tok(), Accept: 'text/html' } })
      .then(function (r) { return r.text(); }).then(function (html) { frame.srcdoc = html; }).catch(function () { frame.srcdoc = '<p style="font-family:sans-serif;padding:20px">Preview unavailable.</p>'; });
    // The preview follows the writing (2026-11-04): the unsaved email drawn by the template preview (sender = you).
    var pvTimer = null;
    function livePreview() {
      clearTimeout(pvTimer);
      pvTimer = setTimeout(function () {
        var v = { layout: f.layout.value, subject: f.subject.value.trim() || 'Preview', preheader: f.preheader.value.trim() || null, headline: f.headline.value.trim() || null,
          intro: f.intro.value.trim() || null, video: f.video.value || null, highlights: [].map.call(hl.querySelectorAll('input:checked'), function (i) { return i.value; }),
          canvas: cvp.value() };
        fetch(apiBase() + '/sales/campaign-templates/preview', { method: 'POST', headers: { Authorization: 'Bearer ' + tok(), Accept: 'text/html', 'Content-Type': 'application/json' }, body: JSON.stringify(v) })
          .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); }).then(function (html) { var y = 0; try { y = frame.contentWindow.scrollY; } catch (e) {} frame.srcdoc = html; frame.onload = function () { try { frame.contentWindow.scrollTo(0, y); } catch (e) {} }; })
          .catch(function () {});
      }, 600);
    }
    ['subject', 'preheader', 'headline', 'intro', 'video', 'layout'].forEach(function (k) {
      f[k].addEventListener(f[k].tagName === 'SELECT' ? 'change' : 'input', function () { if (k === 'layout') layoutRows(f.layout.value, introRow, rows, f.intro); livePreview(); });
    });
    hl.addEventListener('change', livePreview);
  }

  /* A subscriber email: a finished design (App\Support\SubscriberCampaign) - the name and subject change here. */
  /* "I want to write this one" (2026-11-05): a finished design becomes an editable layout - the rich editor opens. */
  function writeOwn(c, reload) {
    var pick = layoutSelect('custom', false);
    var go = iconized(el('button', { class: 'kc-btn p', 'data-kt-always-save': '1', onclick: async function () {
      var lab = pick.options[pick.selectedIndex].text;
      var ok = KT.confirm ? await KT.confirm('Write this email yourself?', { description: 'It changes from the finished ' + (c.template_label || '') + ' design to “' + lab + '”, and the editor opens. The subject, the people on the campaign and the schedule stay.' }) : window.confirm('Write this email yourself?');
      if (!ok) return;
      go.disabled = true;
      try { await Api.patch('/sales/campaigns/' + c.id, { layout: pick.value }); toast('✍️', 'Ready to write', 'The editor is open.', '#16A34A'); reload(); }
      catch (e) { go.disabled = false; toast('⚠️', 'Could not change it', e.message, '#DC2626'); }
    } }, ['✍️ Write it myself']));
    return el('div', { style: 'margin-top:16px;padding-top:14px;border-top:1px dashed #E2E8F0' }, [
      el('div', { style: 'font-weight:800;font-size:14px;color:#0F172A' }, ['Want to write your own words?']),
      el('div', { class: 'kc-sub', style: 'margin:4px 0 10px' }, ['A finished design can’t be edited. Switch to a layout you write in: the rich editor opens with text styles, pictures, links, a film and a Book-a-demo button.']),
      el('div', { class: 'kc-row' }, [pick, go]),
    ]);
  }

  function drawTemplateEditor(pane, c, reload, sched) {
    var locked = c.status === 'sending' || c.status === 'sent';
    var name = el('input', { value: c.name }), subj = el('input', { value: c.subject }), from = fromSelect(c);
    if (locked) { name.disabled = true; subj.disabled = true; from.disabled = true; }
    var status = el('div', { class: 'kc-sub', style: 'min-height:16px;margin:6px 0' });
    var save = el('button', { class: 'kc-btn p', disabled: locked ? true : null, onclick: async function () {
      save.disabled = true; status.textContent = 'Saving…';
      try { await Api.patch('/sales/campaigns/' + c.id, { name: name.value.trim(), subject: subj.value.trim(), from_account_id: from.value ? +from.value : null }); toast('✓', 'Saved', '', '#16A34A'); reload(); }
      catch (e) { save.disabled = false; status.textContent = e.message || 'Could not save.'; }
    } }, ['💾 Save']);
    var left = el('div', { class: 'kc-card' }, [
      el('div', { style: 'background:#F5F3FF;border:1px solid #E4DDFB;border-radius:12px;padding:12px 14px;margin-bottom:14px' }, [
        el('b', { style: 'color:#5B21B6' }, ['📬 Subscriber email: ' + c.template_label]),
        el('div', { class: 'kc-sub', style: 'margin-top:4px' }, ['A finished KiddieTrac design sent to the subscriber list from ' + (c.from_account_id ? (c.from_label || 'the mailbox chosen below') : 'KiddieTrac’s no-reply address (Reply-To info@kiddietrac.com)') + '. Each person gets their own unsubscribe link; unsubscribing also takes them off the subscriber list. Nobody receives the same subscriber email twice.']),
      ]),
      el('label', { class: 'kc-f' }, ['Campaign name', el('small', {}, ['Only your team sees this.']), name]),
      el('label', { class: 'kc-f' }, ['Send from', el('small', {}, [FROM_HINT]), from]),
      el('label', { class: 'kc-f' }, ['Subject', subj]),
      status, save,
      locked ? null : writeOwn(c, reload),
    ]);
    var frame = el('iframe', { title: 'Email preview' });
    var right = el('div', { class: 'kc-prev' }, [el('div', { class: 'kc-row', style: 'justify-content:space-between;padding:10px 14px;background:#fff;border-bottom:1px solid #E2E8F0' }, [
      el('b', { style: 'font-size:13px;color:#334155' }, ['Preview']), el('span', { class: 'kc-sub' }, ['As it arrives. Links here are not tracked.'])]), frame]);
    pane.appendChild(templateBar(c, reload, sched, function () { return { layout: c.layout, subject: subj.value.trim() }; }));
    pane.appendChild(el('div', { class: 'kc-edit' }, [left, right]));
    fetch(apiBase() + '/sales/campaigns/' + c.id + '/preview', { headers: { Authorization: 'Bearer ' + tok(), Accept: 'text/html' } })
      .then(function (r) { return r.text(); }).then(function (html) { frame.srcdoc = html; }).catch(function () { frame.srcdoc = '<p style="font-family:sans-serif;padding:20px">Preview unavailable.</p>'; });
  }

  function drawPeople(pane, c, people, reload) {
    var bar = el('div', { class: 'kc-row', style: 'justify-content:space-between;margin-bottom:10px' });
    var adds = el('div', { class: 'kc-row' });
    if (c.status !== 'sent') {
      adds.appendChild(el('button', { class: 'kc-btn p', onclick: function () { pickLeads(c, reload); } }, ['🎯 Add from Leads']));
      adds.appendChild(el('button', { class: 'kc-btn', onclick: function () { pasteList(c, reload); } }, ['📋 Paste or upload a list']));
      // Everyone on the KiddieTrac subscriber list (2026-10-27)
      adds.appendChild(el('button', { class: 'kc-btn', onclick: async function (ev) {
        var b = ev.currentTarget; b.disabled = true;
        try { var r = await Api.post('/sales/campaigns/' + c.id + '/recipients', { audience: 'subscribers' }); addResult(r); reload(); }
        catch (e) { b.disabled = false; toast('⚠️', 'Could not add', e.message, '#DC2626'); }
      } }, ['📬 Add all subscribers' + (OPTS && OPTS.subscribers != null ? ' (' + OPTS.subscribers + ')' : '')]));
    }
    var search = el('input', { placeholder: 'Search name, company or email…', style: 'padding:8px 11px;border:1.5px solid #E2E8F0;border-radius:10px;min-width:240px;font:500 13.5px inherit' });
    bar.appendChild(adds); bar.appendChild(search);
    pane.appendChild(bar);
    var FILTERS = [['all', 'Everyone'], ['queued', 'Waiting'], ['sent', 'Sent'], ['opened', 'Opened'], ['clicked', 'Clicked'], ['responded', 'Responded'], ['quiet', 'Sent, not opened'], ['problem', 'Failed / skipped']];
    var filter = 'all';
    var chips = el('div', { class: 'kc-chips' });
    var count = function (k) { return people.filter(match(k)).length; };
    FILTERS.forEach(function (fl) {
      chips.appendChild(el('button', { class: 'kc-chip' + (fl[0] === filter ? ' on' : ''), onclick: function () { filter = fl[0]; [].forEach.call(chips.children, function (b) { b.classList.remove('on'); }); this.classList.add('on'); draw(); } }, [fl[1] + ' · ' + count(fl[0])]));
    });
    pane.appendChild(chips);
    var host = el('div', { class: 'kc-card', style: 'padding:6px 8px;overflow-x:auto' });
    pane.appendChild(host);
    search.addEventListener('input', draw);
    function match(k) {
      return function (p) {
        if (k === 'all') return true; if (k === 'queued') return p.status === 'queued'; if (k === 'sent') return p.status === 'sent';
        if (k === 'opened') return !!p.opened_at; if (k === 'clicked') return !!p.clicked_at; if (k === 'responded') return p.response && p.response !== 'unsubscribed';
        if (k === 'quiet') return p.status === 'sent' && !p.opened_at; if (k === 'problem') return p.status === 'failed' || p.status === 'skipped'; return true;
      };
    }
    function draw() {
      var q = search.value.trim().toLowerCase();
      var rows = people.filter(match(filter)).filter(function (p) { return !q || [p.name, p.company, p.email].join(' ').toLowerCase().indexOf(q) > -1; });
      host.innerHTML = '';
      if (!people.length) { host.appendChild(el('div', { style: 'padding:30px;text-align:center;color:#64748B' }, ['Nobody on this campaign yet. Add people from Leads, or paste a list.'])); return; }
      if (!rows.length) { host.appendChild(el('div', { style: 'padding:24px;text-align:center;color:#64748B' }, ['Nobody matches.'])); return; }
      var tb = el('tbody');
      rows.forEach(function (p) {
        var actions = el('td', { style: 'text-align:right;white-space:nowrap' });
        var act = function (label, action) { return el('button', { class: 'kc-btn', title: label, onclick: async function (ev) {
          ev.stopPropagation();
          try { var r = await Api.post('/sales/campaigns/' + c.id + '/recipients/' + p.id + '/action', { action: action });
            toast('✓', label, action === 'unsubscribe' ? 'They will not be emailed again.' : (r.lead_id ? 'They are in Leads with a follow-up task.' : ''), '#16A34A'); reload(); }
          catch (e) { toast('⚠️', 'Could not do that', e.message, '#DC2626'); }
        } }, [label]); };
        if (p.lead_id) actions.appendChild(el('button', { class: 'kc-btn', title: '🎯 Open lead', onclick: function (ev) { ev.stopPropagation(); go('sales-lead?id=' + p.lead_id); } }, ['🎯 Open lead']));
        if (p.status === 'sent' && p.response !== 'replied' && p.response !== 'booked' && p.response !== 'unsubscribed') actions.appendChild(act('💬 Mark replied', 'replied'));
        if (p.status === 'sent' && !p.response) actions.appendChild(act('🙋 Mark interested', 'interested'));
        if (!p.lead_id) actions.appendChild(act('➕ Add to Leads', 'lead'));
        if (p.response !== 'unsubscribed') actions.appendChild(act('🚫 Unsubscribe', 'unsubscribe'));
        if (p.status !== 'sent' && p.status !== 'sending') actions.appendChild(el('button', { class: 'kc-btn', title: '🗑 Remove', onclick: async function (ev) {
          ev.stopPropagation(); try { await Api.delete('/sales/campaigns/' + c.id + '/recipients/' + p.id); reload(); } catch (e) { toast('⚠️', 'Could not remove', e.message, '#DC2626'); }
        } }, ['🗑 Remove']));
        tb.appendChild(el('tr', {}, [
          el('td', {}, [el('div', { style: 'font-weight:700;color:#0F172A' }, [p.name || p.email]), el('div', { class: 'kc-sub' }, [[p.company, p.name ? p.email : null].filter(Boolean).join(' · ')])]),
          el('td', {}, [pill(RSTATUS, p.status), p.status === 'failed' && p.error ? el('div', { class: 'kc-sub', title: p.error, style: 'max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis' }, [p.error]) : null,
            p.status === 'skipped' && p.skip_reason ? el('div', { class: 'kc-sub' }, [p.skip_reason]) : null, p.sent_at ? el('div', { class: 'kc-sub' }, [when(p.sent_at)]) : null]),
          el('td', {}, [p.opened_at ? el('div', {}, ['👀 ' + when(p.opened_at)]) : el('span', { class: 'kc-sub' }, ['—']), p.open_count > 1 ? el('div', { class: 'kc-sub' }, [p.open_count + ' opens']) : null]),
          el('td', {}, [p.clicked_at ? el('div', {}, ['🔗 ' + ({ video: 'Watched the film', demo: 'Demo link', plans: 'Plans', site: 'Website' }[p.last_link] || 'Clicked')]) : el('span', { class: 'kc-sub' }, ['—']), p.clicked_at ? el('div', { class: 'kc-sub' }, [when(p.clicked_at)]) : null]),
          el('td', {}, [p.response ? pill(RESP, p.response) : el('span', { class: 'kc-sub' }, ['—']), p.lead_stage ? el('div', { class: 'kc-sub' }, ['Lead: ' + p.lead_stage]) : null]),
          actions,
        ]));
      });
      host.appendChild(el('table', { class: 'kc-tbl' }, [el('thead', {}, [el('tr', {}, ['Person', 'Status', 'Opened', 'Clicked', 'Response', ''].map(function (h) { return el('th', {}, [h]); }))]), tb]));
    }
    draw();
  }

  function drawResults(pane, c, people) {
    var n = c.counts, sent = n.sent || 0;
    var step = function (label, v, of) { var pct = of ? Math.round(v * 100 / of) : 0; return el('div', { class: 'kc-fstep' }, [el('span', {}, [label]), el('div', { class: 'kc-bar' }, [el('i', { style: 'width:' + pct + '%' })]), el('span', {}, [v + (of ? ' · ' + pct + '%' : '')])]); };
    pane.appendChild(el('div', { class: 'kc-card' }, [
      el('h3', { style: 'margin:0 0 14px;color:' + NAVY }, ['How it went']),
      el('div', { class: 'kc-funnel' }, [step('📤 Sent', sent, n.recipients), step('👀 Opened', n.opened, sent), step('🔗 Clicked', n.clicked, sent), step('🙋 Responded', n.responded, sent)]),
      el('p', { class: 'kc-sub', style: 'margin:14px 0 0' }, ['Opens are counted when the email’s images load, so people who block images open without being counted. Clicks and responses are exact.']),
    ]));
    var kinds = { interested: 0, replied: 0, booked: 0 };
    people.forEach(function (p) { if (kinds[p.response] != null) kinds[p.response]++; });
    pane.appendChild(el('div', { class: 'kc-tiles' }, [tile(kinds.booked, '📅 Booked a demo'), tile(kinds.replied, '💬 Replied'), tile(kinds.interested, '🙋 Asked for info'), tile(n.unsubscribed, '🚫 Unsubscribed')]));
    var hot = people.filter(function (p) { return p.response && p.response !== 'unsubscribed'; });
    var warm = people.filter(function (p) { return !p.response && p.clicked_at; });
    var listOf = function (title, rows, empty) {
      var box = el('div', { class: 'kc-card' }, [el('h3', { style: 'margin:0 0 10px;color:' + NAVY }, [title])]);
      if (!rows.length) { box.appendChild(el('div', { class: 'kc-sub' }, [empty])); return box; }
      rows.forEach(function (p) {
        box.appendChild(el('div', { class: 'kc-row', style: 'justify-content:space-between;padding:8px 0;border-top:1px solid #F1F5F9' }, [
          el('div', {}, [el('b', {}, [p.name || p.email]), el('span', { class: 'kc-sub' }, [' · ' + [p.company, p.email].filter(Boolean).join(' · ')])]),
          el('div', { class: 'kc-row' }, [p.response ? pill(RESP, p.response) : el('span', { class: 'kc-sub' }, ['Clicked ' + when(p.clicked_at)]),
            p.lead_id ? el('button', { class: 'kc-btn', onclick: function () { go('sales-lead?id=' + p.lead_id); } }, ['🎯 Open lead']) : null]),
        ]));
      });
      return box;
    };
    pane.appendChild(listOf('🔥 Responded: follow up', hot, 'No responses yet. They appear here, in Leads and in Follow-ups the moment someone answers.'));
    pane.appendChild(listOf('🌤 Clicked but has not answered', warm, 'Nobody in this group yet.'));
  }

  /* ───────────────────────────── adding people ───────────────────────────── */
  function consentSelect(c) {
    var s = el('select');
    (OPTS ? OPTS.consent : []).forEach(function (v) { var o = el('option', { value: v.key }, [v.label]); if (v.key === c.consent_basis) o.selected = true; s.appendChild(o); });
    return s;
  }
  function addResult(r) {
    var bits = [r.added + ' added'];
    if (r.duplicates) bits.push(r.duplicates + ' already on it'); if (r.unsubscribed) bits.push(r.unsubscribed + ' unsubscribed (skipped)');
    if (r.invalid) bits.push(r.invalid + ' without a valid email'); if (r.over_limit) bits.push(r.over_limit + ' over the 5,000 limit');
    toast('👥', 'People added', bits.join(' · '), '#16A34A');
  }
  async function pickLeads(c, reload) {
    var body = el('div', {}, [el('div', { class: 'kc-sub' }, ['Loading leads…'])]);
    modal('🎯 Add people from Leads', body, true);
    var d; try { d = await Api.get('/sales/leads'); } catch (e) { body.innerHTML = ''; body.appendChild(el('div', { style: 'color:#B91C1C' }, [e.message || 'Could not load leads.'])); return; }
    var leads = (d.leads || []).filter(function (l) { return l.email; });
    body.innerHTML = '';
    var q = el('input', { placeholder: 'Search…', style: 'flex:1;min-width:200px;padding:8px 11px;border:1.5px solid #E2E8F0;border-radius:10px' });
    var stage = el('select', { style: 'padding:8px;border:1.5px solid #E2E8F0;border-radius:10px' });
    [['', 'Every stage']].concat((d.stages || []).map(function (s) { return [s.key || s.stage, (s.label || s.key || s.stage) + (s.count != null ? ' (' + s.count + ')' : '')]; })).forEach(function (s) { stage.appendChild(el('option', { value: s[0] }, [s[1]])); });
    var all = el('input', { type: 'checkbox' });
    var list = el('div', { style: 'max-height:46vh;overflow:auto;border:1px solid #E2E8F0;border-radius:12px;margin:10px 0' });
    var picked = {};
    var cons = consentSelect(c);
    var go2 = el('button', { class: 'kc-btn p' }, ['Add 0 people']);
    function rowsNow() { var t = q.value.trim().toLowerCase(), st = stage.value; return leads.filter(function (l) { return (!st || l.stage === st) && (!t || [l.name, l.company, l.email].join(' ').toLowerCase().indexOf(t) > -1); }); }
    function sync() { var n = Object.keys(picked).length; go2.textContent = 'Add ' + n + ' ' + (n === 1 ? 'person' : 'people'); go2.disabled = !n; }
    function draw() {
      list.innerHTML = '';
      rowsNow().forEach(function (l) {
        var cb = el('input', { type: 'checkbox' }); cb.checked = !!picked[l.id];
        cb.addEventListener('change', function () { if (cb.checked) picked[l.id] = 1; else delete picked[l.id]; sync(); });
        list.appendChild(el('label', { class: 'kc-row', style: 'padding:9px 12px;border-bottom:1px solid #F1F5F9;cursor:pointer;flex-wrap:nowrap' }, [cb,
          el('div', { style: 'min-width:0;flex:1' }, [el('b', {}, [l.company || l.name]), el('div', { class: 'kc-sub' }, [[l.company ? l.name : null, l.email, l.stage].filter(Boolean).join(' · ')])])]));
      });
      if (!list.children.length) list.appendChild(el('div', { class: 'kc-sub', style: 'padding:16px' }, ['No leads match.']));
    }
    all.addEventListener('change', function () { rowsNow().forEach(function (l) { if (all.checked) picked[l.id] = 1; else delete picked[l.id]; }); draw(); sync(); });
    q.addEventListener('input', draw); stage.addEventListener('change', draw);
    go2.addEventListener('click', async function () {
      go2.disabled = true;
      try { var r = await Api.post('/sales/campaigns/' + c.id + '/recipients', { lead_ids: Object.keys(picked).map(Number), consent_basis: cons.value }); closeModal(); addResult(r); reload(); }
      catch (e) { go2.disabled = false; toast('⚠️', 'Could not add', e.message, '#DC2626'); }
    });
    body.appendChild(el('div', { class: 'kc-row' }, [q, stage, el('label', { class: 'kc-row', style: 'font-size:13px;font-weight:700' }, [all, 'Select all shown'])]));
    body.appendChild(list);
    body.appendChild(el('label', { class: 'kc-f' }, ['Why they may receive it (CASL)', cons]));
    body.appendChild(go2);
    draw(); sync();
  }
  /* PASTE ANY LIST OF ADDRESSES (2026-11-11, Anthony: "paste or upload a list of emails addresses in sales campaign
     doesn't recognize a paste of emails with commas or semi colons with a number of email addresses"). Every line was
     read as ONE person - its first address, with the rest of the line taken as a name and a centre - so a run of
     addresses copied from Outlook, Gmail or a spreadsheet row ("a@x.ca, b@y.ca; c@z.ca") added one person. Now:
       · a line with two or more addresses is a list: each address is a person, with the name written beside it when
         there is one (Jane Doe <jane@x.ca>, "Doe, Jane" <jane@x.ca>);
       · a line with one address is a spreadsheet row as before: email, name, centre in any order (a header is skipped);
       · commas, semicolons, tabs, spaces and new lines all separate; mailto: and the <> come off; an address twice
         counts once (rows.dupes says how many). The server checks every address again (addRecipients). */
  var EMAIL_ONE = /^[^\s@<>,;"]+@[^\s@<>,;"]+\.[A-Za-z]{2,}$/;
  function parseList(text) {
    var out = [], seen = {}, dupes = 0;
    var push = function (r) {
      var k = String(r.email || '').toLowerCase();
      if (k) { if (seen[k]) { dupes++; return; } seen[k] = 1; }
      out.push(r);
    };
    var ADDR = /[A-Za-z0-9._%+'\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/g;
    String(text || '').replace(/mailto:/gi, '').split(/\r?\n/).forEach(function (line) {
      if (!line.trim()) return;
      var found = line.match(ADDR) || [];
      if (found.length >= 2) {
        // A list on one line: each address, with the name just before it when it is written Name <address>.
        var re = /(?:"([^"]*)"|([^<>,;"\t@]*?))\s*<\s*([^<>\s]+@[^<>\s]+?)\s*>|([A-Za-z0-9._%+'\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,})/g;
        var m;
        while ((m = re.exec(line))) {
          var email = String(m[3] || m[4] || '').trim();
          var name = String(m[1] || m[2] || '').trim().replace(/^[,;\s]+|[,;\s]+$/g, '');
          if (email) push({ email: email, name: name || null, company: null });
        }
        return;
      }
      var cells = line.split(/\t|,|;/).map(function (x) { return x.trim().replace(/^"|"$/g, ''); });
      if (!found.length) { if (/e-?mail/i.test(line)) return; push({ email: '', name: cells[0] || null }); return; }   // a header row is skipped
      var emailIdx = cells.findIndex(function (x) { return x.indexOf(found[0]) >= 0; });
      var rest = cells.filter(function (x, i) { return i !== emailIdx && x; });
      push({ email: found[0], name: rest[0] || null, company: rest[1] || null });
    });
    out.dupes = dupes;
    return out;
  }
  function pasteList(c, reload) {
    var ta = el('textarea', { rows: '10', style: 'width:100%;box-sizing:border-box;min-height:180px', placeholder: 'Paste addresses - separated by commas, semicolons, spaces or new lines:\njane@sunrise-montessori.ca, owner@littlesteps.ca; Sam Lee <sam@brightstart.ca>\n\n...or one person per line: email, name, centre\njane@sunrise-montessori.ca, Jane Doe, Sunrise Montessori' });
    var file = el('input', { type: 'file', accept: '.csv,.txt,.tsv,text/csv,text/plain', 'data-kt-noface': '1' });
    var info = el('div', { class: 'kc-sub', style: 'min-height:16px;margin:6px 0 2px;font-weight:700' });
    var prev = el('div', { class: 'kc-sub', style: 'font-size:12px;overflow-wrap:anywhere;margin:0 0 6px' });
    var cons = consentSelect(c);
    var b = el('button', { class: 'kc-btn p', disabled: true }, ['Add people']);
    var rows = [];
    function refresh() {
      var all = parseList(ta.value);
      var good = all.filter(function (r) { return EMAIL_ONE.test(r.email); });
      var bad = all.length - good.length, n = function (x) { return Number(x).toLocaleString(); };
      var parts = [];
      if (good.length) parts.push(n(good.length) + ' email address' + (good.length === 1 ? '' : 'es') + ' found');
      if (all.dupes) parts.push(n(all.dupes) + ' repeat' + (all.dupes === 1 ? '' : 's') + ' removed');
      if (bad) parts.push(n(bad) + ' line' + (bad === 1 ? '' : 's') + ' without a valid address skipped');
      var over = good.length > 5000;
      if (over) parts.push('only 5,000 can be added at once - split the list');
      info.textContent = all.length ? (parts.join(' · ') || 'No email addresses found yet') : '';
      info.style.color = over || (all.length && !good.length) ? '#B45309' : '';
      prev.textContent = good.length ? good.slice(0, 4).map(function (r) { return r.name ? r.name + ' <' + r.email + '>' : r.email; }).join(', ')
        + (good.length > 4 ? ' … and ' + n(good.length - 4) + ' more' : '') : '';
      rows = good;   // only addresses go to the server
      b.disabled = !good.length || over;
      b.textContent = 'Add ' + n(good.length) + ' ' + (good.length === 1 ? 'person' : 'people');
    }
    ta.addEventListener('input', refresh);
    file.addEventListener('change', function () { var f = file.files && file.files[0]; if (!f) return; var rd = new FileReader(); rd.onload = function () { ta.value = String(rd.result || ''); refresh(); }; rd.readAsText(f); });
    b.addEventListener('click', async function () {
      b.disabled = true;
      try { var r = await Api.post('/sales/campaigns/' + c.id + '/recipients', { rows: rows, consent_basis: cons.value }); closeModal(); addResult(r); reload(); }
      catch (e) { b.disabled = false; toast('⚠️', 'Could not add', e.message, '#DC2626'); }
    });
    modal('📋 Paste or upload a list', el('div', {}, [
      el('p', { class: 'kc-sub', style: 'margin:0 0 8px' }, ['Paste a list of addresses (commas, semicolons, spaces or new lines between them), rows from a spreadsheet (email, name, centre), or upload a CSV. The same address twice counts once. People who unsubscribed before are skipped automatically.']),
      ta, el('div', { class: 'kc-row', style: 'margin:8px 0' }, [el('span', { class: 'kc-sub' }, ['or a CSV file:']), file]), info, prev,
      el('label', { class: 'kc-f' }, ['Why they may receive it (CASL)', cons]),
      b,
    ]), true);
  }

  /* ───────────────────────────── test + send ───────────────────────────── */
  /* TEST ADDRESSES (2026-11-05, Anthony: "test to send to my own email (ability to configure a test email)"): up to five,
     remembered for your next test (server: user_ui_prefs); unsaved changes on the Email tab are saved first. */
  var DIRTY = null;   // the Email tab's "save what is on screen" while it has unsaved changes
  async function testSend(c) {
    if (DIRTY && DIRTY.id === c.id && document.body.contains(DIRTY.el)) {
      var saved = await DIRTY.save();
      if (!saved) { toast('⚠️', 'Save the email first', 'Your changes could not be saved, so the test was not sent.', '#B45309'); return; }
    }
    var list = ((OPTS && OPTS.test_to) || []).slice();
    if (!list.length && me().email) list = [me().email];
    var em = el('textarea', { rows: '3', placeholder: 'you@example.com', 'aria-label': 'Test addresses' });
    em.value = list.join('\n');
    var st = el('div', { class: 'kc-sub', style: 'min-height:16px;margin:6px 0;color:#B91C1C' });
    var b = el('button', { class: 'kc-btn p', onclick: async function () {
      var to = em.value.split(/[\s,;]+/).map(function (x) { return x.trim(); }).filter(Boolean);
      var bad = to.filter(function (x) { return !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x); });
      if (!to.length) { st.textContent = 'Type at least one email address.'; return; }
      if (bad.length) { st.textContent = 'Check this address: ' + bad[0]; return; }
      if (to.length > 5) { st.textContent = 'Up to 5 addresses per test.'; return; }
      b.disabled = true;
      try {
        var r = await Api.post('/sales/campaigns/' + c.id + '/test', { emails: to });
        if (OPTS) OPTS.test_to = to;
        closeModal();
        var miss = (r && r.failed) || [];
        toast(miss.length ? '⚠️' : '✉️', miss.length ? 'Test partly sent' : 'Test sent', 'Sent to ' + ((r && r.sent) || to).join(', ') + (miss.length ? '. Not sent to ' + miss.map(function (x) { return x.email; }).join(', ') : '') + '. The subject starts with [TEST].', miss.length ? '#B45309' : '#16A34A');
      } catch (e) { b.disabled = false; st.textContent = e.message || 'The test could not be sent.'; }
    } }, ['✉️ Send test']);
    b.dataset.ktIconized = '1';
    modal('✉️ Send a test', el('div', {}, [el('label', { class: 'kc-f' }, ['Send a test copy to', el('small', {}, ['Up to 5 addresses, one per line. They are remembered for your next test.']), em]),
      el('p', { class: 'kc-sub', style: 'margin:0 0 8px' }, ['Exactly the real email, from ' + (c.template ? 'KiddieTrac' : 'sales@kiddietrac.com') + ', marked [TEST]. Nothing is tracked or counted.']), st, b]));
    setTimeout(function () { try { em.focus(); } catch (e) {} }, 60);
  }
  function sendCampaign(c, main, ctx) {
    var n = c.queued;
    var basis = (OPTS ? OPTS.consent : []).filter(function (x) { return x.key === c.consent_basis; })[0];
    var ok = el('input', { type: 'checkbox' });
    var b = el('button', { class: 'kc-btn p', disabled: true }, ['📤 Send to ' + n + ' ' + (n === 1 ? 'person' : 'people')]);
    ok.addEventListener('change', function () { b.disabled = !ok.checked; });
    b.addEventListener('click', async function () {
      b.disabled = true;
      try { await Api.post('/sales/campaigns/' + c.id + '/send', { confirm: n }); closeModal(); toast('📤', 'Sending', 'It goes out in small batches from sales@ over the next few minutes.', '#16A34A'); renderCampaign(main, ctx); }
      catch (e) { b.disabled = false; toast('⚠️', 'Not sent', e.message, '#DC2626'); }
    });
    modal('📤 Send “' + c.name + '”', el('div', {}, [
      el('div', { class: 'kc-card', style: 'background:#F8FAFC' }, [
        el('div', {}, [el('b', {}, [String(n)]), ' ' + (n === 1 ? 'person' : 'people') + ' will receive “' + c.subject + '” from ', el('b', {}, [c.template ? 'KiddieTrac' : 'sales@kiddietrac.com']), ' now.']),
        el('div', { class: 'kc-sub', style: 'margin-top:6px' }, ['About 30 a minute, so ' + (n > 30 ? 'it takes roughly ' + Math.ceil(n / 30) + ' minutes' : 'it goes out within a minute') + '. Replies come to the sales mailbox; you can pause at any time.']),
      ]),
      el('label', { class: 'kc-row', style: 'align-items:flex-start;gap:10px;font-size:13.5px;color:#334155;margin:4px 0 14px;cursor:pointer;flex-wrap:nowrap' }, [ok,
        el('span', {}, ['I confirm everyone on this list may receive it under Canada’s anti-spam law (CASL): ' + (basis ? basis.label.toLowerCase() : 'consent or an implied-consent rule') + '. People can unsubscribe from every email.'])]),
      b,
    ]));
  }

  function scheduleCampaign(c, main, ctx) {
    var at = el('input', { type: 'datetime-local', value: c.scheduled_local || '' });
    var info = el('div', { class: 'kc-sub', style: 'min-height:16px;margin:6px 0;color:#B91C1C' });
    var save = el('button', { class: 'kc-btn p', onclick: async function () {
      if (!at.value) { info.textContent = 'Pick a date and time.'; return; }
      save.disabled = true;
      try { await Api.post('/sales/campaigns/' + c.id + '/schedule', { send_at: at.value.slice(0, 16) }); closeModal(); toast('🗓', 'Scheduled', 'It goes out at that time once it is approved.', '#16A34A'); renderCampaign(main, ctx); }
      catch (e) { save.disabled = false; info.textContent = e.message || 'Could not schedule it.'; }
    } }, ['🗓 Save time']);
    var clear = c.scheduled_for ? el('button', { class: 'kc-btn', onclick: async function () {
      try { await Api.post('/sales/campaigns/' + c.id + '/schedule', { send_at: null }); closeModal(); toast('🗓', 'Unscheduled', '', '#16A34A'); renderCampaign(main, ctx); }
      catch (e) { info.textContent = e.message || 'Could not unschedule it.'; }
    } }, ['Remove the schedule']) : null;
    modal('🗓 Schedule “' + c.name + '”', el('div', {}, [
      el('label', { class: 'kc-f' }, ['Send at (Eastern Time)', at]),
      el('p', { class: 'kc-sub', style: 'margin:0 0 8px' }, ['It goes out at this time only after it has been approved. Changing the time asks for approval again.']),
      info, el('div', { class: 'kc-row' }, [save, clear]),
    ]));
  }

  ['sales_rep', 'platform_admin', 'agency_admin'].forEach(function (role) {
    var guard = function (fn) { return function (main, ctx) { if (!salesOk()) { main.innerHTML = '<div style="padding:48px 20px;text-align:center;color:#64748B">🔒 Campaigns are for the sales team and superadmins.</div>'; return; } return fn(main, ctx); }; };
    KT.Shell.registerScreen(role + ':sales-campaigns', guard(renderList));
    KT.Shell.registerScreen(role + ':sales-campaign', guard(renderCampaign));
  });
})(window);
