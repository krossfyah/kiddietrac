/* ═══════════════════════════════════════════════════════════════════════
   Educator points & rewards (2026-10-20)

   Anthony: "add points system for educators to encourage them to use the features of the
   app such as observations, daily logs, walks etc which adds up at the end of the month
   which directors/admins can issue out a monetary bonus, pay out or giftcard".

   #my-points         educators, home visitors (and directors): this month's points, what
                      earned them, how to earn more, the centre leaderboard (points only)
                      and the rewards they have received, gift cards included.
   #educator-rewards  directors and admins: every educator's month, the Award dialog
                      (bonus in the next pay run, payout, gift card bought anywhere, or the
                      gift card service), the agency's point values and caps, and for the
                      superadmin the gift card service connection.

   Points are worked out on the server from the records the work left behind
   (App\Support\EducatorPoints); nothing here keeps a tally.
   ═══════════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(v, cur) { return '$' + (Number(v) || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (cur && cur !== 'CAD' ? ' ' + cur : ''); }
  function num(v) { return (Number(v) || 0).toLocaleString('en-CA'); }
  function toast(msg, kind) { try { if (KT.Dom && KT.Dom.toast) KT.Dom.toast(msg, kind || 'info'); } catch (e) {} }
  function monthLabel(m) {
    var p = String(m || '').split('-');
    if (p.length !== 2) { return m || ''; }
    return new Date(Date.UTC(+p[0], +p[1] - 1, 15)).toLocaleDateString('en-CA', { month: 'long', year: 'numeric', timeZone: 'UTC' });
  }
  function dateOf(ts) {
    if (!ts) { return ''; }
    try { if (KT.fmtDateTime) { return KT.fmtDateTime(ts, { dateOnly: true }); } } catch (e) {}
    return String(ts).slice(0, 10);
  }
  function me() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function isPlatform() { var u = me(); return Array.isArray(u.roles) && u.roles.indexOf('platform_admin') !== -1; }
  function isAgencyAdmin() { var u = me(); return isPlatform() || (Array.isArray(u.roles) && u.roles.indexOf('agency_admin') !== -1) || u.role_key === 'agency_admin'; }
  var GIFT_PENDING = ['requested', 'awaiting_funds', 'sent'];

  var INPUT = 'height:30px;padding:3px 8px;border:1px solid #CBD5E1;border-radius:7px;font-size:13px;box-sizing:border-box;';
  var CARD = 'background:#fff;border:1px solid #E7EDF3;border-radius:14px;padding:14px 16px;';
  var STATUS = {
    pending_payroll: ['Waiting for pay run', '#B45309', '#FEF3C7'],
    paid: ['Paid', '#065F46', '#D1FAE5'],
    sent: ['Sent', '#065F46', '#D1FAE5'],
    requested: ['Being prepared', '#1D4ED8', '#DBEAFE'],
    awaiting_funds: ['Waiting for Giftbit funds', '#B45309', '#FEF3C7'],
    redeemed: ['Redeemed', '#065F46', '#D1FAE5'],
    expired: ['Expired', '#475569', '#F1F5F9'],
    failed: ['Failed', '#B91C1C', '#FEE2E2'],
    cancelled: ['Cancelled', '#475569', '#F1F5F9'],
  };
  /* THE HOUSE POPUP HEADER (2026-11-02 sweep): the navy-to-teal band with the teal line, white title, and the guard's ✕
     in its top-right (kt-modal-guard presses the popup's own Cancel). Was a plain bold title on white. */
  var DLG_HEAD = 'background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;color:#fff;margin:-18px -18px 12px;padding:14px 56px 13px 18px;border-radius:16px 16px 0 0;';
  function dlgHead(title, sub) {
    return '<div class="kt-dlg-head" style="' + DLG_HEAD + '"><div style="font-size:16px;font-weight:800;color:#fff;">' + title + '</div>'
      + (sub ? '<div data-sub="1" style="font-size:12.5px;color:rgba(255,255,255,.85);margin-top:2px;">' + sub + '</div>' : '') + '</div>';
  }
  function autoLine(a, canEdit) {
    if (!a) { return ''; }
    return '<div class="rw-autoline" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12.5px;color:#475569;margin-bottom:10px;">'
      + '<span>🏅 Automatic awards: <b style="color:' + (a.enabled ? '#15803D' : '#64748B') + ';">' + (a.enabled ? 'On' : 'Off') + '</b>'
      + (a.enabled ? ' · ' + (a.mode === 'auto' ? 'sent automatically' : 'prepared for review') + ' · ' + esc(a.schedule_label) : '')
      + (a.pending ? ' · <b>' + a.pending + '</b> waiting for review' : '') + '</span>'
      + '<span aria-hidden="true">·</span><a href="#educator-award-settings" style="color:#0E7C90;font-weight:700;text-decoration:none;">' + (canEdit ? 'Change in Settings' : 'See in Settings') + '</a>'
      + '<span aria-hidden="true">·</span><a href="#educator-award-settings?tab=points" style="color:#0E7C90;font-weight:700;text-decoration:none;">⚙️ Point values &amp; rewards</a></div>';
  }
  function pill(st) {
    var s = STATUS[st] || [st, '#475569', '#F1F5F9'];
    return '<span style="display:inline-block;font-size:11px;font-weight:800;color:' + s[1] + ';background:' + s[2] + ';border-radius:999px;padding:2px 8px;">' + esc(s[0]) + '</span>';
  }
  /* 190px, not 160 (2026-11-02 phone sweep): on a phone the field font is 16px and "November, 2026" plus the
     calendar icon needs ~180px; at 160 it read "November, 202". */
  function monthInput(id, value) {
    return '<input type="month" id="' + id + '" data-kt-nofit="1" value="' + esc(value) + '" style="' + INPUT + 'width:190px;max-width:100%;">';
  }
  function pts(n) { return Number(n) === 1 ? 'pt' : 'pts'; }
  // "educator" / "centre_director" -> "Educator" / "Centre director"
  function roleLabel(r) { var t = String(r || '').replace(/_/g, ' '); return t.charAt(0).toUpperCase() + t.slice(1); }

  /* ─────────────────────────── My points ─────────────────────────── */
  var myMonth = null;
  async function renderMine(main) {
    // Same rule as Educator rewards: a month change redraws the body, never the hero.
    var body = freshBody(screenRoot(main, 'mp-root', 1100), 'mp-body');
    var d;
    try { d = await Api.get('/educator-points/me' + (myMonth ? '?month=' + myMonth : '')); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="padding:16px;">' + esc((e && e.message) || 'Could not load your points') + '</div>'; return; }
    myMonth = d.month;
    if (!d.enabled) {
      body.innerHTML = '<div class="kt-card" style="padding:16px;color:#475569;">Points are switched off for your agency right now.</div>';
      return;
    }
    var acts = d.activities || [];
    var by = (d.me && d.me.by) || {};
    var h = '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">'
      + '<div style="font-size:13.5px;color:#475569;max-width:640px;">Every observation, daily log, walk and photo you share earns points. At the end of the month your director can turn them into a bonus, a payout or a gift card.</div>'
      + monthInput('mp-month', d.month) + '</div>';

    h += '<div style="display:grid;grid-template-columns:minmax(200px,260px) 1fr;gap:12px;margin-bottom:12px;" class="kt-pts-top">'
      // NOT CARD (2026-11-02): CARD starts "background:#fff;", which kt-mobile-tint.css matches on a phone and repaints
      // with a pale tint (!important), so the white month, number and "points" were white on near-white.
      + '<div style="border-radius:14px;padding:14px 16px;background:linear-gradient(135deg,#0B2545,#13708A);color:#fff;border:0;display:flex;flex-direction:column;justify-content:center;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:1px;opacity:.8;">' + esc(monthLabel(d.month).toUpperCase()) + '</div>'
      + '<div style="font-size:44px;font-weight:900;line-height:1.05;margin-top:4px;">⭐ ' + num(d.me.total) + '</div>'
      + '<div style="font-size:13px;opacity:.85;">points</div></div>'
      + '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:8px;">What earned them</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px;">'
      + acts.map(function (a) {
        var b = by[a.key] || { count: 0, counted: 0, points: 0 };
        var capped = b.count > b.counted;
        return '<div style="border:1px solid #EEF2F6;border-radius:10px;padding:8px 10px;">'
          + '<div style="font-size:12.5px;color:#475569;">' + a.icon + ' ' + esc(a.label) + '</div>'
          + '<div style="font-size:18px;font-weight:800;color:#0F172A;">' + num(b.points) + ' <span style="font-size:11.5px;font-weight:600;color:#94A3B8;">pts</span></div>'
          + '<div style="font-size:11.5px;color:#94A3B8;">' + num(b.count) + ' logged' + (capped ? ' · daily limit reached on some days' : '') + '</div></div>';
      }).join('') + '</div></div></div>';

    h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;">';
    if (d.leaderboard) {
      h += '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:8px;">🏆 Your centre this month</div>'
        + (d.leaderboard.length ? d.leaderboard.slice(0, 15).map(function (r) {
          return '<div style="display:flex;align-items:center;gap:10px;padding:6px 8px;border-radius:9px;' + (r.me ? 'background:#ECFEFF;font-weight:800;' : '') + '">'
            + '<span style="width:26px;text-align:center;font-weight:800;color:' + (r.rank <= 3 ? '#B45309' : '#94A3B8') + ';">' + (r.rank === 1 ? '🥇' : r.rank === 2 ? '🥈' : r.rank === 3 ? '🥉' : r.rank) + '</span>'
            + '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(r.name) + (r.me ? ' (you)' : '') + '</span>'
            + '<span style="font-weight:800;color:#0F172A;">' + num(r.points) + '</span></div>';
        }).join('') : '<div style="color:#94A3B8;font-size:13px;">No points yet this month.</div>')
        + '</div>';
    }
    h += '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:8px;">💡 How to earn points</div>'
      + acts.filter(function (a) { return a.points > 0; }).map(function (a) {
        return '<div style="display:flex;justify-content:space-between;gap:10px;padding:4px 0;font-size:13px;color:#334155;">'
          + '<span>' + a.icon + ' ' + esc(a.label) + '</span><span style="white-space:nowrap;"><b>' + a.points + '</b> ' + pts(a.points)
          + (a.cap ? ' <span style="color:#94A3B8;">· up to ' + a.cap + '/day</span>' : '') + '</span></div>';
      }).join('') + '</div>';

    var aw = d.awards || [];
    h += '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:8px;">🎁 Your rewards</div>'
      + (aw.length ? aw.map(function (a) {
        var card = (a.gift_code || a.gift_link)
          ? '<div style="margin-top:6px;"><button type="button" class="kt-btn" data-reveal="' + a.id + '" style="padding:4px 10px;font-size:12px;">Show my gift card</button>'
            + '<div data-card="' + a.id + '" hidden style="margin-top:6px;background:#F8FAFC;border:1px dashed #94A3B8;border-radius:9px;padding:8px 10px;font-size:13px;word-break:break-all;">'
            + (a.gift_code ? '<div><b>Code:</b> <span style="font-family:monospace;">' + esc(a.gift_code) + '</span> <button type="button" class="kt-btn" data-copy="' + esc(a.gift_code) + '" style="padding:2px 8px;font-size:11.5px;">Copy</button></div>' : '')
            + (a.gift_link ? '<div style="margin-top:4px;"><a href="' + esc(a.gift_link) + '" target="_blank" rel="noopener">Open your gift card ↗</a></div>' : '')
            // Tango sends its own "how to use it" text, sometimes as HTML: shown as plain text.
            + (a.redemption_instructions ? '<div style="margin-top:6px;font-size:12px;color:#475569;line-height:1.45;max-height:140px;overflow:auto;">'
                + esc(String(a.redemption_instructions).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()) + '</div>' : '')
            + '</div></div>'
          : (a.method === 'gift_card_provider' && (a.status === 'requested' || a.status === 'awaiting_funds')
              ? '<div style="font-size:12px;color:#64748B;margin-top:4px;">Your gift card is being prepared. It will appear here soon.</div>' : '');
        return '<div style="border-top:1px solid #F1F5F9;padding:8px 0;">'
          + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;">'
          + '<span style="flex:1 1 180px;min-width:0;font-weight:800;color:#0F172A;">' + (a.method === 'thank_you' ? '🏅 ' + esc(a.method_label) : money(a.amount, a.currency) + ' · ' + esc(a.method_label) + (a.brand ? ' (' + esc(a.brand) + ')' : '')) + '</span>' + pill(a.status) + '</div>'
          + '<div style="font-size:12px;color:#64748B;">' + esc(monthLabel(a.month)) + ' · ' + num(a.points) + ' pts' + (a.awarded_by ? ' · from ' + esc(a.awarded_by) : '') + '</div>'
          + (a.note ? '<div style="font-size:12.5px;color:#334155;margin-top:3px;">“' + esc(a.note) + '”</div>' : '')
          + card + '</div>';
      }).join('') : '<div style="color:#94A3B8;font-size:13px;">No rewards yet. Keep logging!</div>')
      + '</div></div>';

    body.innerHTML = h;
    var mi = body.querySelector('#mp-month');
    mi.addEventListener('change', function () { if (/^\d{4}-\d{2}$/.test(mi.value)) { myMonth = mi.value; renderMine(main); } });
    body.querySelectorAll('[data-reveal]').forEach(function (b) {
      b.addEventListener('click', function () {
        var box = body.querySelector('[data-card="' + b.getAttribute('data-reveal') + '"]');
        box.hidden = !box.hidden; b.textContent = box.hidden ? 'Show my gift card' : 'Hide';
      });
    });
    body.querySelectorAll('[data-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        try { navigator.clipboard.writeText(b.getAttribute('data-copy')); b.textContent = 'Copied'; } catch (e) {}
      });
    });
    if (window.matchMedia && window.matchMedia('(max-width:700px)').matches) {
      var top = body.querySelector('.kt-pts-top'); if (top) { top.style.gridTemplateColumns = '1fr'; }
    }
  }

  /* ─────────────────────────── Educator rewards ─────────────────────────── */
  var rwMonth = null, rwCentre = '', rwTab = 'month', rwTabFromLink = false;
  /* THE HERO STAYS PUT (2026-10-20). Anthony: "educator rewards the hero banner disappears when
     navigating to the subtabs within that section". Every subtab click, month change and save
     called this again, and it rewrote main.innerHTML, taking the hero the shell had placed in
     #appMain with it. Now the screen owns ONE root and only redraws inside it: the tab strip and
     a body that is swapped for a fresh node (so its click handler is never added twice), with
     the old content left showing until the new arrives, so nothing flickers. */
  function freshBody(root, id) {
    var old = root.querySelector('#' + id);
    var b = document.createElement('div');
    b.id = id;
    b.innerHTML = old ? old.innerHTML : '<div style="color:#64748B;">Loading…</div>';
    if (old) { old.replaceWith(b); } else { root.appendChild(b); }
    return b;
  }
  function screenRoot(main, id, maxw) {
    var root = main.querySelector('#' + id);
    if (!root) {
      main.setAttribute('data-kt-pretty', '1');
      main.innerHTML = '<div id="' + id + '" style="padding:14px 24px;box-sizing:border-box;' + (maxw ? 'max-width:' + maxw + 'px;' : 'width:100%;') + '"></div>';
      root = main.querySelector('#' + id);
    }
    return root;
  }

  async function renderRewards(main) {
    // Full window (2026-11-02 sweep): a work screen with a wide table, no 1240px column.
    var fresh = !main.querySelector('#rw-root');
    var root = screenRoot(main, 'rw-root', 0);
    if (!document.getElementById('rw-pts-css')) {
      var css = document.createElement('style'); css.id = 'rw-pts-css';
      css.textContent = '@media (max-width:768px){#appMain .rw-awline{white-space:normal !important;margin:2px 0;}#appMain .rw-awline > span{white-space:nowrap;}}';
      document.head.appendChild(css);
    }
    var tabs = root.querySelector('#rw-tabs');
    if (!tabs) { tabs = document.createElement('div'); tabs.id = 'rw-tabs'; root.insertBefore(tabs, root.firstChild); }
    /* A link names its tab (#educator-rewards?tab=auto from the bell/email, ?tab=history from Settings): honoured
       whenever the screen is opened afresh, not only the first time this session. */
    var tabm = fresh && /[?&]tab=(auto|history|month|settings|giftbit|provider)\b/.exec(location.hash);
    /* EVERY SETTING LIVES IN SETTINGS (2026-11-02, Anthony: "remove the automatic rewards subtab - not needed here" and
       "educator award settings needs to be added to the settings section"). The Automatic awards, Point values, Gift
       cards and KiddieTrac gift card account tabs moved to Settings → Educator awards (#educator-award-settings); an old
       link to one of them (the bell/email's ?tab=auto) lands on the matching tab there. This screen is the work only. */
    var MOVED = { auto: 'educator-award-settings?tab=auto', settings: 'educator-award-settings?tab=points',
      giftbit: 'payment-providers?tab=giftcards', provider: 'payment-providers?tab=giftcards' };
    if (tabm && MOVED[tabm[1]]) { location.replace('#' + MOVED[tabm[1]]); return; }
    if (tabm) { rwTab = tabm[1]; }
    if (rwTab !== 'month' && rwTab !== 'history') { rwTab = 'month'; }
    rwTabFromLink = true;
    var list = [['month', '⭐ Points & awards'], ['history', '🗂 Award history']];
    // The band (kt-tab-band.js) styles the strip and gives phones one sideways row with ‹ › arrows: no inline wrap.
    tabs.innerHTML = '<div role="tablist" aria-label="Educator rewards" style="display:flex;margin-bottom:12px;">' + list.map(function (t) {
      var on = rwTab === t[0];
      return '<button type="button" role="tab" aria-selected="' + (on ? 'true' : 'false') + '" data-rwtab="' + t[0] + '">' + t[1] + '</button>';
    }).join('') + '</div>';
    tabs.querySelectorAll('[data-rwtab]').forEach(function (b) { b.addEventListener('click', function () { rwTab = b.getAttribute('data-rwtab'); renderRewards(main); }); });
    var body = freshBody(root, 'rw-body');
    if (rwTab === 'history') { return drawHistory(main, body); }

    var d;
    try { d = await Api.get('/educator-points?month=' + (rwMonth || '') + (rwCentre ? '&centre_id=' + rwCentre : '')); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="padding:16px;">' + esc((e && e.message) || 'Could not load') + '</div>'; return; }
    rwMonth = d.month;
    var rows = d.rows || [];
    var acts = d.activities || [];
    var totalPts = rows.reduce(function (a, r) { return a + r.total; }, 0);
    var given = 0;
    rows.forEach(function (r) { (r.awards || []).forEach(function (a) { given += Number(a.amount) || 0; }); });

    var h = '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">'
      + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">' + monthInput('rw-month', d.month)
      + ((d.centres || []).length > 1 ? '<select id="rw-centre" data-kt-nofit="1" style="' + INPUT + 'width:220px;min-width:0;max-width:100%;"><option value="">All centres</option>'
        + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(rwCentre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>' : '')
      + '</div>'
      + '<div style="font-size:13px;color:#475569;"><b>' + rows.length + '</b> people · <b>' + num(totalPts) + '</b> points · <b>' + money(given) + '</b> awarded in ' + esc(monthLabel(d.month)) + '</div></div>';
    h += autoLine(d.auto, d.can_edit_settings);
    if (!d.settings.enabled) {
      h += '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:13px;">Points are switched off, so educators don’t see them. Turn them on in <a href="#educator-award-settings?tab=points" style="color:#92400E;font-weight:800;">Settings → Educator awards</a>.</div>';
    }
    if (!d.settings.rate_amount) {
      h += '<div style="font-size:12.5px;color:#64748B;margin-bottom:8px;">Tip: set a points-to-dollars rate in <a href="#educator-award-settings?tab=points" style="color:#0E7C90;font-weight:700;">Settings → Educator awards</a> and the Award dialog suggests an amount.</div>';
    }
    h += '<div class="kt-card"><table data-kt-rows-key="educator-rewards" style="width:100%;"><thead><tr><th>Educator</th><th style="text-align:right;">Points</th><th>What earned them</th><th>Awarded</th><th></th></tr></thead><tbody>'
      + (rows.length ? rows.map(function (r) {
        var bits = acts.map(function (a) { var b = r.by[a.key]; return b && b.count ? a.icon + ' ' + num(b.count) : ''; }).filter(Boolean).join(' · ');
        var aw = (r.awards || []).map(function (a) {
          if (a.method === 'thank_you') { return '<div class="rw-awline" style="font-size:12.5px;white-space:nowrap;">🏅 ' + esc(a.method_label) + ' ' + pill(a.status) + '</div>'; }
          return '<div class="rw-awline" style="font-size:12.5px;white-space:nowrap;">' + money(a.amount, a.currency) + ' ' + esc(a.method_label) + ' ' + pill(a.status) + '</div>';
        }).join('');
        var pending = (r.awards || []).filter(function (a) { return a.status === 'pending_payroll' || a.status === 'failed'; });
        var gifts = (r.awards || []).filter(function (a) { return a.method === 'gift_card_provider' && GIFT_PENDING.indexOf(a.status) !== -1; });
        return '<tr><td><strong>' + esc(r.name) + '</strong><div style="font-size:11.5px;color:#94A3B8;">' + esc(roleLabel(r.role)) + (r.centres.length ? ' · ' + esc(r.centres.join(', ')) : '') + '</div></td>'
          + '<td style="text-align:right;font-weight:800;font-size:15px;">' + num(r.total) + (r.suggested ? '<div style="font-size:11px;font-weight:600;color:#64748B;white-space:nowrap;">≈ ' + money(r.suggested) + '</div>' : '') + '</td>'
          + '<td style="font-size:12.5px;color:#475569;">' + (bits || '<span style="color:#CBD5E1;">—</span>') + '</td>'
          + '<td>' + (aw || '<span style="color:#CBD5E1;">—</span>') + '</td>'
          + '<td style="text-align:center;white-space:nowrap;">'
          + (r.can_award ? '<button type="button" class="rw-award" data-uid="' + r.user_id + '" title="Give a reward">🎁 Award</button>'
              + ' <button type="button" class="rw-gift" data-uid="' + r.user_id + '" title="Issue a gift card">🎁 Issue gift card</button>'
              + ' <button type="button" class="rw-thanks" data-uid="' + r.user_id + '" title="Email a certificate of appreciation">🏅 Send a thank-you award</button>'
            : '<span style="font-size:11.5px;color:#94A3B8;">Admin awards</span>')
          + pending.map(function (a) { return ' <button type="button" class="rw-cancel" data-aid="' + a.id + '" title="Cancel this bonus">Cancel ' + money(a.amount) + ' bonus</button>'; }).join('')
          + gifts.map(function (a) {
            return ' <button type="button" class="rw-refresh" data-aid="' + a.id + '" title="Ask Giftbit for this card\u2019s status">Check gift card status</button>'
              + ' <button type="button" class="rw-cancel" data-gift="1" data-aid="' + a.id + '" title="Cancel this gift card">Cancel ' + money(a.amount) + ' gift card</button>';
          }).join('')
          + '</td></tr>';
      }).join('') : '<tr><td colspan="5" style="text-align:center;color:#94A3B8;padding:24px;">No educators found for this view.</td></tr>')
      + '</tbody></table></div>'
      + '<div style="font-size:11.5px;color:#94A3B8;margin-top:8px;">Points come from what each person logged in KiddieTrac this month, with a daily limit per activity. Money awarded is never shown to other staff.</div>';
    body.innerHTML = h;

    var mi = body.querySelector('#rw-month');
    mi.addEventListener('change', function () { if (/^\d{4}-\d{2}$/.test(mi.value)) { rwMonth = mi.value; renderRewards(main); } });
    var cs = body.querySelector('#rw-centre');
    if (cs) { cs.addEventListener('change', function () { rwCentre = cs.value; renderRewards(main); }); }
    body.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('.rw-award');
      if (a) { var row = rows.filter(function (x) { return String(x.user_id) === a.getAttribute('data-uid'); })[0]; if (row) { openAward(row, d, function () { renderRewards(main); }); } return; }
      var gb = e.target.closest && e.target.closest('.rw-gift');
      if (gb) { var grow = rows.filter(function (x) { return String(x.user_id) === gb.getAttribute('data-uid'); })[0]; if (grow) { openAward(grow, d, function () { renderRewards(main); }, { method: 'gift_card' }); } return; }
      var tb = e.target.closest && e.target.closest('.rw-thanks');
      if (tb) { var trow = rows.filter(function (x) { return String(x.user_id) === tb.getAttribute('data-uid'); })[0]; if (trow) { openThanks(trow, d, function () { renderRewards(main); }); } return; }
      var rf = e.target.closest && e.target.closest('.rw-refresh');
      if (rf) {
        Api.post('/educator-points/awards/' + rf.getAttribute('data-aid') + '/refresh', {})
          .then(function (res) { toast('Gift card: ' + ((STATUS[res.award.status] || [res.award.status])[0]), 'success'); renderRewards(main); })
          .catch(function (err) { toast((err && err.message) || 'Could not check', 'error'); });
        return;
      }
      var c = e.target.closest && e.target.closest('.rw-cancel');
      if (c) {
        var go = function () {
          Api.delete('/educator-points/awards/' + c.getAttribute('data-aid'))
            .then(function () { toast('Bonus cancelled', 'success'); renderRewards(main); })
            .catch(function (err) { toast((err && err.message) || 'Could not cancel', 'error'); });
        };
        var isGift = c.getAttribute('data-gift') === '1';
        if (KT.confirm) {
          KT.confirm(isGift
            ? { title: 'Cancel this gift card?', description: 'Giftbit cancels it if it hasn\u2019t been redeemed, and the money goes back to your Giftbit balance. The educator will no longer see it.', okLabel: 'Cancel gift card' }
            : { title: 'Cancel this bonus?', description: 'It will not be added to their next pay.', okLabel: 'Cancel bonus' }).then(function (ok) { if (ok) { go(); } });
        }
        else { go(); }
      }
    });
  }

  function openAward(row, d, onDone, preset) {
    var cur = d.settings.currency || 'CAD';
    var prov = d.provider || {};
    /* Giftbit and/or Tango Card (2026-10-20). With both connected the director picks which;
       pv is the one in use. An older server answer (Giftbit only) is read the same way. */
    var provs = prov.providers || (prov.connected ? [{ key: 'giftbit', label: 'Giftbit', env: prov.env, source: prov.source, balance: prov.balance, test: prov.env === 'testbed' }] : []);
    var pv = provs[0] || null;
    function provNote(p) {
      return 'Bought from your ' + esc(p.label) + ' account and shown to them in the app'
        + (p.test ? ' (' + (p.key === 'tango' ? 'sandbox' : 'testbed') + ': no real cards)' : '')
        + (p.balance ? '. Balance: <b>' + money(p.balance.available, cur) + '</b>' : '') + '.';
    }
    var ov = document.createElement('div');
    ov.className = 'kt-scrim';
    ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('data-kt-scroll', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481000;display:flex;align-items:center;justify-content:center;padding:16px;';
    var L = 'display:block;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin:12px 0 5px;';
    var F = INPUT + 'width:100%;';
    // The agency's default (Settings → Educator awards → Point values & rewards); a gift card service not connected falls back.
    var defM = (d.settings && d.settings.default_method) || 'payroll_bonus';
    if (defM === 'gift_card_provider' && !pv) { defM = 'gift_card_manual'; }
    var opt = function (v, label, sub, dis) {
      return '<label style="display:flex;gap:9px;align-items:flex-start;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;cursor:' + (dis ? 'not-allowed' : 'pointer') + ';opacity:' + (dis ? '.5' : '1') + ';">'
        + '<input type="radio" name="rw-m" value="' + v + '"' + (dis ? ' disabled' : '') + (v === defM ? ' checked' : '') + ' style="margin:2px 0 0;flex:0 0 auto;width:16px;height:16px;">'
        + '<span><b style="font-size:13px;color:#0F172A;">' + label + '</b><span style="display:block;font-size:11.5px;color:#64748B;">' + sub + '</span></span></label>';
    };
    ov.innerHTML = '<div data-kt-scroll="1" style="background:#fff;border-radius:16px;max-width:520px;width:100%;max-height:92vh;overflow:auto;padding:18px;box-sizing:border-box;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + dlgHead('🎁 Reward ' + esc(row.name), esc(monthLabel(d.month)) + ' · <b>' + num(row.total) + '</b> points' + (row.suggested ? ' · suggested ' + money(row.suggested, cur) : ''))
      + '<label style="' + L + '">Amount (' + esc(cur) + ')</label>'
      + '<input id="rw-amt" type="text" inputmode="decimal" data-kt-nofit="1" value="' + (row.suggested ? row.suggested.toFixed(2) : '') + '" placeholder="0.00" style="' + F + 'width:160px;">'
      + '<label style="' + L + '">How</label><div style="display:grid;gap:6px;">'
      + opt('payroll_bonus', 'Bonus in the next pay run', 'Added as a line when you next run payroll for them, and marked paid then.')
      + opt('payout', 'Payout', 'Paid outside KiddieTrac (e-transfer, cheque). Recorded as paid now.')
      + opt('gift_card_manual', 'Gift card you bought', 'Enter the card code or link; they see it in the app.')
      + opt('gift_card_provider', 'Send a gift card' + (provs.length === 1 ? ' via ' + esc(provs[0].label) : (provs.length > 1 ? ' via Giftbit or Tango Card' : '')),
          pv ? '<span id="rw-pvnote">' + provNote(pv) + '</span>'
            : 'Connect your gift card account first: an agency admin adds Giftbit or Tango Card in Settings → Payment providers → Gift cards.', !pv)
      + '</div>'
      + '<div id="rw-ref" hidden><label style="' + L + '">Reference (optional)</label><input id="rw-reference" type="text" data-kt-nofit="1" maxlength="120" placeholder="e.g. e-transfer #4821" style="' + F + '"></div>'
      + '<div id="rw-card" hidden><label style="' + L + '">Brand</label><input id="rw-brand" type="text" data-kt-nofit="1" maxlength="80" placeholder="e.g. Amazon, Tim Hortons" style="' + F + '">'
      + '<label style="' + L + '">Card code</label><input id="rw-code" type="text" data-kt-nofit="1" maxlength="500" autocomplete="off" style="' + F + '">'
      + '<label style="' + L + '">or link</label><input id="rw-link" type="url" data-kt-nofit="1" maxlength="1000" placeholder="https://…" style="' + F + '">'
      + '<div style="font-size:11.5px;color:#64748B;margin-top:4px;">Stored encrypted. Only the educator can see it, in My points.</div></div>'
      + '<div id="rw-prov" hidden>'
      + (provs.length > 1 ? '<label style="' + L + '">Send through</label><div id="rw-pv" style="display:flex;gap:6px;flex-wrap:wrap;">'
          + provs.map(function (p, i) {
            return '<button type="button" data-pv="' + i + '" style="border:2px solid ' + (i === 0 ? '#13708A' : '#E2E8F0') + ';background:' + (i === 0 ? '#ECFEFF' : '#fff')
              + ';border-radius:999px;padding:6px 14px;font:inherit;font-size:13px;font-weight:800;color:#0F172A;cursor:pointer;">' + esc(p.label) + '</button>';
          }).join('') + '</div>' : '')
      + '<label style="' + L + '">Gift card</label>'
      + '<input id="rw-bfind" type="search" data-kt-nofit="1" placeholder="Search brands\u2026" style="' + F + 'margin-bottom:6px;">'
      + '<div id="rw-blist" data-kt-scroll="1" style="max-height:220px;overflow:auto;border:1px solid #E2E8F0;border-radius:10px;padding:6px;display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:6px;">'
      + '<div style="grid-column:1/-1;color:#94A3B8;font-size:12.5px;padding:6px;">Loading gift cards\u2026</div></div>'
      + '<div id="rw-bnote" style="font-size:12px;color:#475569;margin-top:6px;"></div></div>'
      + '<label style="' + L + '">Note to them (optional)</label><textarea id="rw-note" rows="2" maxlength="1000" placeholder="Thank you for the amazing observations this month!" style="width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:8px;padding:7px 9px;font:inherit;font-size:13px;"></textarea>'
      + '<div id="rw-who" style="margin-top:10px;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:9px;padding:8px 11px;font-size:12.5px;color:#0C4A6E;line-height:1.45;">📣 Checking who will be told…</div>'
      + '<div id="rw-err" style="display:none;margin-top:10px;background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:9px;padding:8px 11px;font-size:12.5px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">'
      + '<button type="button" id="rw-x" class="kt-btn">Cancel</button><button type="button" id="rw-go" class="kt-btn kt-btn-primary">Give reward</button></div></div>';
    document.body.appendChild(ov);
    var q = function (s) { return ov.querySelector(s); };
    function method() { var m = ov.querySelector('input[name="rw-m"]:checked'); return m ? m.value : 'payroll_bonus'; }
    /* GIFTBIT BRANDS. Loaded once, the first time the Giftbit option is picked: the catalogue
       in this agency's currency, cached on the server for 6 hours. "Let them choose" sends
       Giftbit's full catalogue for the region, and the educator picks the brand when they open it. */
    var brands = null, chosen = '', chosenInfo = null, brandsBy = {};
    function paintBrands() {
      var box = q('#rw-blist'), qq = (q('#rw-bfind').value || '').trim().toLowerCase();
      var tile = function (code, name, img) {
        var on = chosen === code;
        return '<button type="button" data-bc="' + esc(code) + '" style="border:2px solid ' + (on ? '#13708A' : '#E2E8F0') + ';background:' + (on ? '#ECFEFF' : '#fff')
          + ';border-radius:10px;padding:6px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;font:inherit;font-size:11.5px;font-weight:700;color:#0F172A;min-height:84px;">'
          + (img ? '<img src="' + esc(img) + '" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'" style="width:100%;height:46px;object-fit:contain;">' : '<span style="font-size:26px;">🎁</span>')
          + '<span style="text-align:center;line-height:1.2;">' + esc(name) + '</span></button>';
      };
      var list = (brands || []).filter(function (b) { return !qq || b.name.toLowerCase().indexOf(qq) !== -1; });
      // "Let them choose" is Giftbit's full-catalogue card; Tango cards are always one item.
      box.innerHTML = (pv && pv.key === 'giftbit' ? tile('', 'Let them choose', null) : '') + list.slice(0, 160).map(function (b) { return tile(b.code, b.name, b.image); }).join('')
        + (brands && !brands.length ? '<div style="grid-column:1/-1;color:#B45309;font-size:12.5px;padding:6px;">' + esc(pv ? pv.label : '') + ' returned no gift cards for ' + esc(cur) + '.</div>' : '');
    }
    function loadBrands() {
      if (!pv) { return; }
      if (brandsBy[pv.key]) { brands = brandsBy[pv.key]; paintBrands(); return; }
      brands = null;
      var key = pv.key;
      brandsBy[key] = [];
      q('#rw-blist').innerHTML = '<div style="grid-column:1/-1;color:#94A3B8;font-size:12.5px;padding:6px;">Loading ' + esc(pv.label) + ' gift cards…</div>';
      Api.get('/educator-points/giftbit/brands?provider=' + key).then(function (r) { brandsBy[key] = (r && r.brands) || []; if (pv.key === key) { brands = brandsBy[key]; paintBrands(); } })
        .catch(function (e) { delete brandsBy[key]; q('#rw-blist').innerHTML = '<div style="grid-column:1/-1;color:#B91C1C;font-size:12.5px;padding:6px;">' + esc((e && e.message) || 'Could not load the gift cards') + '</div>'; });
    }
    function noteFor(info) {
      if (!info) {
        return pv && pv.key === 'tango' ? 'Pick the gift card to send.'
          : 'They choose any brand Giftbit offers in ' + esc(cur) + ' when they open it.';
      }
      var vals = info.variable ? (money(info.min) + ' to ' + money(info.max)) : info.prices.map(function (p) { return money(p); }).join(', ');
      return '<b>' + esc(info.name) + '</b>: ' + vals + (info.disclaimer ? '<div style="font-size:11px;color:#94A3B8;margin-top:3px;max-height:60px;overflow:auto;">' + esc(info.disclaimer) + '</div>' : '');
    }
    function sync() {
      var m = method();
      q('#rw-ref').hidden = m !== 'payout';
      q('#rw-card').hidden = m !== 'gift_card_manual';
      q('#rw-prov').hidden = m !== 'gift_card_provider';
      if (m === 'gift_card_provider') { loadBrands(); q('#rw-bnote').innerHTML = noteFor(chosenInfo); }
    }
    q('#rw-bfind').addEventListener('input', paintBrands);
    var pvBox = q('#rw-pv');
    if (pvBox) {
      pvBox.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-pv]');
        if (!b) { return; }
        pv = provs[+b.getAttribute('data-pv')];
        Array.prototype.forEach.call(pvBox.children, function (x) {
          var on = x === b; x.style.borderColor = on ? '#13708A' : '#E2E8F0'; x.style.background = on ? '#ECFEFF' : '#fff';
        });
        var n = q('#rw-pvnote'); if (n) { n.innerHTML = provNote(pv); }
        chosen = ''; chosenInfo = null; q('#rw-bnote').innerHTML = noteFor(null);
        loadBrands();
      });
    }
    q('#rw-blist').addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-bc]');
      if (!b) { return; }
      chosen = b.getAttribute('data-bc'); chosenInfo = null; paintBrands();
      q('#rw-bnote').innerHTML = chosen ? 'Checking the values ' + esc(chosen) + ' allows\u2026' : noteFor(null);
      if (!chosen) { return; }
      Api.get('/educator-points/giftbit/brands/' + encodeURIComponent(chosen) + '?provider=' + (pv ? pv.key : 'giftbit')).then(function (r) {
        chosenInfo = r.brand; q('#rw-bnote').innerHTML = noteFor(chosenInfo);
        var a = q('#rw-amt');
        if (!chosenInfo.variable && chosenInfo.prices.length && chosenInfo.prices.indexOf(parseFloat(a.value)) === -1) {
          a.value = chosenInfo.prices[0].toFixed(2);   // fixed-value cards: start on a value that exists
        }
      }).catch(function () { q('#rw-bnote').textContent = 'Could not read that brand\u2019s values.'; });
    });
    /* WHO GETS TOLD (2026-10-20): the educator gets an in-app notice and the email; the
       agency admins and their centre's director are BCC'd on it. Said before the click. */
    var first = String(row.name || '').split(' ')[0] || 'They';
    Api.get('/educator-points/notify-preview?user_id=' + row.user_id).then(function (w) {
      var names = (w.bcc || []).map(function (b) { return esc(b.name) + ' <span style="color:#64748B;">(' + esc(b.role) + ')</span>'; });
      q('#rw-who').innerHTML = '📣 <b>' + esc(first) + '</b> will get an in-app notice'
        + (w.educator_email ? ' and an email' : ' (no email: their address is missing or email is turned off)') + '.'
        + (names.length ? ' BCC: ' + names.join(', ') + '.' : ' No admin or director to BCC.')
        + (/^gift_card/.test(method()) ? ' The card itself is never in the email.' : '');
    }).catch(function () { q('#rw-who').textContent = '📣 ' + first + ' will get an in-app notice and an email; agency admins and their director are BCC’d.'; });
    ov.querySelectorAll('input[name="rw-m"]').forEach(function (r) { r.addEventListener('change', sync); });
    if (preset && preset.method) {
      // "gift_card": the gift card service when it is connected, else a card they bought.
      var want = preset.method === 'gift_card' ? (pv ? 'gift_card_provider' : 'gift_card_manual') : preset.method;
      var rb = ov.querySelector('input[name="rw-m"][value="' + want + '"]');
      if (rb && !rb.disabled) { rb.checked = true; }
    }
    sync();
    function close() { if (ov.parentNode) { ov.parentNode.removeChild(ov); } }
    q('#rw-x').onclick = close;
    q('#rw-go').onclick = function () {
      var err = q('#rw-err');
      var amt = parseFloat(String(q('#rw-amt').value).replace(/[^0-9.]/g, ''));
      if (!(amt > 0)) { err.textContent = 'Enter an amount.'; err.style.display = ''; return; }
      var m = method();
      var payload = { user_id: row.user_id, month: d.month, amount: amt, method: m, note: q('#rw-note').value.trim() || null };
      if (m === 'payout') { payload.reference = q('#rw-reference').value.trim() || null; }
      if (m === 'gift_card_manual') {
        payload.brand = q('#rw-brand').value.trim() || null;
        payload.gift_code = q('#rw-code').value.trim() || null;
        payload.gift_link = q('#rw-link').value.trim() || null;
        if (!payload.gift_code && !payload.gift_link) { err.textContent = 'Add the card code or its link.'; err.style.display = ''; return; }
      }
      if (m === 'gift_card_provider') {
        payload.brand = chosen || null;
        payload.provider = pv ? pv.key : 'giftbit';
        if (payload.provider === 'tango' && !chosen) { err.textContent = 'Pick the Tango Card gift card to send.'; err.style.display = ''; return; }
        if (chosenInfo) {
          var bad = chosenInfo.variable ? (amt < chosenInfo.min || amt > chosenInfo.max) : chosenInfo.prices.indexOf(amt) === -1;
          if (bad) { err.innerHTML = 'That amount doesn\u2019t suit ' + noteFor(chosenInfo).split('<div')[0]; err.style.display = ''; return; }
        }
        if (pv && pv.balance && pv.balance.available < amt) { err.textContent = 'Your ' + pv.label + ' balance is ' + money(pv.balance.available, cur) + ', not enough for this card. Add funds in ' + pv.label + ' first.'; err.style.display = ''; return; }
      }
      err.style.display = 'none';
      var btn = q('#rw-go'); btn.disabled = true; btn.textContent = 'Giving…';
      Api.post('/educator-points/awards', payload).then(function () {
        close();
        toast('Reward given to ' + row.name + '. They’ve been told.', 'success');
        if (onDone) { onDone(); }
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Give reward';
        err.textContent = (e && e.message) || 'Could not give the reward.'; err.style.display = '';
      });
    };
  }

  /* SEND A THANK-YOU AWARD (2026-10-30, Anthony: "send email with an award thanking the educator (nicely designed and
     signed and dated by the agency owner)"). App\Support\ThankYouAward: a certificate in the agency's colours, signed
     by the owner (first name) and dated today; the sender may pick another admin to sign. */
  function openThanks(row, d, onDone) {
    var ov = document.createElement('div');
    ov.className = 'kt-scrim';
    ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('data-kt-scroll', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481000;display:flex;align-items:center;justify-content:center;padding:16px;';
    var L = 'display:block;font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.5px;margin:12px 0 5px;';
    ov.innerHTML = '<div data-kt-scroll="1" style="background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:92vh;overflow:auto;padding:18px;box-sizing:border-box;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + dlgHead('🏅 Thank ' + esc(row.name), 'A certificate of appreciation for ' + esc(monthLabel(d.month)) + ' · <b>' + num(row.total) + '</b> points, emailed to them.')
      + '<label style="' + L + '">Signed by</label><select id="ty-signer" data-kt-nofit="1" style="' + INPUT + 'width:100%;"><option>Loading…</option></select>'
      + '<div id="ty-date" style="font-size:12px;color:#64748B;margin-top:4px;"></div>'
      + '<div style="display:flex;align-items:center;gap:8px;margin:12px 0 5px;"><label style="' + L + 'margin:0;flex:1;">Letter in the email</label>'
      + '<select id="ty-tone" data-kt-nofit="1" title="Tone" style="' + INPUT + 'width:auto;"><option value="warm">Warm</option><option value="formal">Formal</option><option value="short">Short</option></select>'
      + '<button type="button" id="ty-rw" class="kt-btn" style="white-space:nowrap;">✨ Rewrite</button></div>'
      + '<textarea id="ty-note" rows="9" maxlength="3000" placeholder="Writing a letter from ' + esc(String(row.name || '').split(' ')[0] || 'their') + '\'s month…" style="width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:8px;padding:8px 10px;font:inherit;font-size:13px;line-height:1.5;"></textarea>'
      + '<div id="ty-note-hint" style="font-size:11.5px;color:#64748B;margin-top:4px;">Written from their real month: observations, photos, logs and walks. Edit anything before sending.</div>'
      + '<label style="' + L + '">Personal message on the certificate (optional)</label><textarea id="ty-msg" rows="3" maxlength="600" placeholder="Thank you for the beautiful observations and the calm you bring to the toddler room every day." style="width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:8px;padding:7px 9px;font:inherit;font-size:13px;"></textarea>'
      + '<div id="ty-who" style="margin-top:10px;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:9px;padding:8px 11px;font-size:12.5px;color:#0C4A6E;line-height:1.45;">📣 Checking who will be told…</div>'
      + '<div id="ty-err" style="display:none;margin-top:10px;background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:9px;padding:8px 11px;font-size:12.5px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">'
      + '<button type="button" id="ty-x" class="kt-btn">Cancel</button><button type="button" id="ty-go" class="kt-btn kt-btn-primary" disabled>Send award</button></div></div>';
    document.body.appendChild(ov);
    var q = function (s) { return ov.querySelector(s); };
    function close() { if (ov.parentNode) { ov.parentNode.removeChild(ov); } }
    q('#ty-x').onclick = close;
    var first = String(row.name || '').split(' ')[0] || 'They';
    Api.get('/educator-points/thanks?user_id=' + row.user_id).then(function (w) {
      var sg = w.signers || [];
      q('#ty-signer').innerHTML = sg.length ? sg.map(function (s, i) { return '<option value="' + esc(s.key) + '">' + esc(s.first) + ' · ' + esc(s.title) + (i === 0 ? ' (default)' : '') + '</option>'; }).join('')
        : '<option value="">No agency admin to sign</option>';
      q('#ty-date').textContent = 'Dated ' + (w.date || 'today') + '. Only first names are shown in the email.';
      var names = (w.bcc || []).map(function (b) { return esc(b.name) + ' <span style="color:#64748B;">(' + esc(b.role) + ')</span>'; });
      q('#ty-who').innerHTML = '📣 ' + (w.educator_email ? esc(first) + ' gets the email and an in-app notice' : esc(first) + ' has no email address: they get an in-app notice')
        + (names.length ? '; BCC ' + names.join(', ') + '.' : '.');
      q('#ty-go').disabled = !sg.length;
    }).catch(function (e) { q('#ty-who').textContent = (e && e.message) || 'Could not check who will be told.'; });
    var noteAi = false;
    function draft() {
      var b = q('#ty-rw'), ta = q('#ty-note');
      b.disabled = true; b.textContent = '✨ Writing…'; ta.style.opacity = '.6';
      Api.post('/educator-points/thanks/note', { user_id: row.user_id, month: d.month, tone: q('#ty-tone').value, signer: q('#ty-signer').value || null }).then(function (r) {
        ta.value = r.note || ''; noteAi = !!r.ai;
        q('#ty-note-hint').textContent = r.ai ? 'Drafted from their real month: observations, photos, logs and walks. Edit anything before sending.'
          : (r.ai_available ? 'The writing helper is busy, so this is our standard letter. Edit anything before sending.' : 'Our standard letter, filled in from their month. Edit anything before sending.');
      }).catch(function () { q('#ty-note-hint').textContent = 'Could not draft a letter. Write your own, or leave it empty for our standard letter.'; })
        .then(function () { b.disabled = false; b.textContent = '✨ Rewrite'; ta.style.opacity = ''; });
    }
    q('#ty-rw').onclick = draft;
    q('#ty-tone').onchange = draft;
    draft();
    q('#ty-go').onclick = function () {
      var btn = q('#ty-go'), err = q('#ty-err');
      btn.disabled = true; btn.textContent = 'Sending…'; err.style.display = 'none';
      Api.post('/educator-points/thanks', { user_id: row.user_id, month: d.month, message: q('#ty-msg').value.trim() || null, signer: q('#ty-signer').value || null,
        note: q('#ty-note').value.trim() || null, note_ai: noteAi }).then(function () {
        close(); toast('Thank-you award sent to ' + row.name + '.', 'success'); if (onDone) { onDone(); }
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Send award';
        err.textContent = (e && e.message) || 'Could not send it.'; err.style.display = '';
      });
    };
  }

  /* For other screens (the Agency overview's Educator points card): open the same dialogs for one person and month. */
  function withRow(userId, month, fn) {
    return Api.get('/educator-points?month=' + (month || '')).then(function (d) {
      var row = (d.rows || []).filter(function (x) { return String(x.user_id) === String(userId); })[0];
      if (!row) { toast('That person is not on your team for this month.', 'error'); return; }
      if (!row.can_award) { toast('An agency admin gives awards to this person.', 'error'); return; }
      fn(row, d);
    }).catch(function (e) { toast((e && e.message) || 'Could not load', 'error'); });
  }
  KT.educatorAward = {
    open: function (userId, opts) { opts = opts || {}; return withRow(userId, opts.month, function (row, d) { openAward(row, d, opts.onDone, opts.method ? { method: opts.method } : null); }); },
    giftCard: function (userId, month, onDone) { return withRow(userId, month, function (row, d) { openAward(row, d, onDone, { method: 'gift_card' }); }); },
    thanks: function (userId, month, onDone) { return withRow(userId, month, function (row, d) { openThanks(row, d, onDone); }); },
  };


  /* AUTOMATIC THANK-YOU AWARDS (2026-10-31, Anthony: "the thank-you email can you wire it up to have it automatic each
     month or set a threshold or date, time etc"). App\Support\ThankYouAuto.
     2026-11-02 (Anthony: "the automatic awards should be a setting in the settings section"): the SETTINGS moved to
     Settings → Educator awards (#educator-award-settings, drawAutoSettings below), reading and writing the same
     agencies.settings.thank_you_auto through the same GET/PUT /educator-points/auto. This tab keeps the work: the
     drafts waiting for review (edit the letter, Send / Skip / Send all) and what was sent, with a summary and a link. */
  function autoStatusCard(d, s, withToggle, canEdit) {
    return '<div style="' + CARD + 'display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">'
      + '<div style="font-size:30px;line-height:1;">🏅</div>'
      + '<div style="flex:1;min-width:220px;"><div style="font-weight:800;color:#0F172A;font-size:15px;">Automatic thank-you awards are ' + (s.enabled ? '<span style="color:#15803D;">on</span>' : '<span style="color:#64748B;">off</span>') + '</div>'
      + '<div style="font-size:13px;color:#475569;margin-top:3px;">' + (s.enabled
          ? 'Runs ' + esc(d.schedule_label) + ' for the month before, for ' + esc(d.rule_label) + '. ' + (s.mode === 'auto' ? 'They are sent straight away.' : 'An admin reviews and sends them.') + ' Next: ' + esc(d.next_run) + '.'
          : 'Turn it on to thank your educators every month without having to remember. You choose when, who qualifies, and whether you review them first.') + '</div></div>'
      + (withToggle
          ? '<label style="display:inline-flex;gap:8px;align-items:center;font-weight:800;font-size:13px;"><input type="checkbox" id="ta-en"' + (s.enabled ? ' checked' : '') + (canEdit ? '' : ' disabled') + '> On</label>'
          : '<a href="#educator-award-settings" class="kt-btn" style="text-decoration:none;white-space:nowrap;">⚙️ Change in Settings</a>')
      + '</div>';
  }

  async function drawAuto(main, body) {
    var d;
    try { d = await Api.get('/educator-points/auto'); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="padding:16px;">' + esc((e && e.message) || 'Could not load the automatic awards. Try again in a moment.') + '</div>'; return; }
    var s = d.settings;
    var drafts = d.drafts || [];
    var draftsHtml = drafts.length ? '<div style="' + CARD + 'margin-bottom:12px;">'
      + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;"><div style="flex:1;min-width:200px;font-weight:800;color:#0F172A;">✉️ Ready to review (' + drafts.length + ')</div>'
      + '<button type="button" id="ta-all" class="kt-btn kt-btn-primary">Send all ' + drafts.length + '</button></div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-bottom:10px;">Read and edit each letter; nothing goes out until you press Send. Drafts you don’t send expire after ' + s.expire_days + ' days.</div>'
      + drafts.map(function (x) {
        return '<div data-draft="' + x.id + '" style="border:1px solid #E2E8F0;border-radius:12px;padding:12px;margin-bottom:10px;background:#fff;">'
          + '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;"><div style="flex:1;min-width:160px;"><div style="font-weight:800;color:#0F172A;">' + esc(x.name) + '</div>'
          + '<div style="font-size:12px;color:#64748B;">' + esc(x.month_label) + ' · ⭐ ' + num(x.points) + ' points' + (x.letter_ai ? ' · ✨ letter drafted for you' : '') + (x.status === 'sending' ? ' · sending…' : '') + '</div></div>'
          + '<button type="button" class="kt-btn" data-skip="' + x.id + '">Skip</button><button type="button" class="kt-btn kt-btn-primary" data-send="' + x.id + '">Send</button></div>'
          + '<textarea data-letter="' + x.id + '" rows="6" style="' + INPUT + 'width:100%;height:auto;min-height:120px;padding:8px 10px;line-height:1.5;resize:vertical;font-size:13px;">' + esc(x.letter) + '</textarea>'
          + '<input type="text" data-msg="' + x.id + '" value="' + esc(x.message) + '" placeholder="Personal message on the certificate (optional)" style="' + INPUT + 'width:100%;margin-top:6px;">'
          + '</div>';
      }).join('') + '</div>'
      : '<div style="' + CARD + 'margin-bottom:12px;color:#64748B;font-size:13px;">✉️ Nothing is waiting for review.' + (s.enabled && s.mode !== 'auto' ? ' The next drafts are prepared ' + esc(d.next_run) + '.' : '') + '</div>';

    var hist = d.history || [];
    var ST = { sent: ['Sent', '#15803D', '#DCFCE7'], skipped: ['Skipped', '#475569', '#F1F5F9'], expired: ['Expired', '#92400E', '#FEF3C7'], failed: ['Not emailed', '#B91C1C', '#FEE2E2'] };
    var histHtml = '<div style="' + CARD + '"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;"><div style="flex:1;font-weight:800;color:#0F172A;">🕘 Automatic awards so far</div>'
      + '<a href="#educator-rewards?tab=history" class="kt-btn" style="text-decoration:none;">Full award history →</a></div>'
      + (hist.length ? '<table data-kt-export-title="Automatic thank-you awards" style="width:100%;font-size:13px;border-collapse:collapse;"><thead><tr><th style="text-align:left;">Educator</th><th style="text-align:left;">Month</th><th style="text-align:right;">Points</th><th style="text-align:left;">Status</th><th style="text-align:left;">When</th></tr></thead><tbody>'
        + hist.map(function (x) { var st = ST[x.status] || [x.status, '#475569', '#F1F5F9'];
          return '<tr><td>' + esc(x.name) + '</td><td>' + esc(x.month_label) + '</td><td style="text-align:right;">' + num(x.points) + '</td>'
            + '<td><span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800;color:' + st[1] + ';background:' + st[2] + ';">' + st[0] + '</span></td><td>' + esc(dateOf(x.decided_at || x.created_at)) + '</td></tr>'; }).join('')
        + '</tbody></table>' : '<div style="font-size:13px;color:#64748B;">None yet. Automatic awards appear here once the first month has run.</div>') + '</div>';

    // Drawn inside Settings → Educator awards, below the settings (the Educator rewards tab is gone, 2026-11-02).
    body.innerHTML = '<div id="eas-review" style="font-weight:800;color:#0F172A;font-size:15px;margin:18px 0 8px;">✉️ Automatic awards to review and sent</div>' + draftsHtml + histHtml;

    function saveDraft(id) {
      var l = body.querySelector('[data-letter="' + id + '"]'), m = body.querySelector('[data-msg="' + id + '"]');
      return Api.post('/educator-points/auto/drafts/' + id, { letter: l ? l.value : '', message: m ? m.value : '' });
    }
    // One listener per body: a redraw after Send swaps the handler instead of stacking a second (a double send).
    var first = !body.__taClick;
    body.__taClick = async function (e) {
      var sb = e.target.closest('[data-send]'), kb = e.target.closest('[data-skip]'), all = e.target.closest('#ta-all');
      if (sb) {
        var id = sb.getAttribute('data-send'); sb.disabled = true;
        try { await saveDraft(id); await Api.post('/educator-points/auto/drafts/' + id + '/send', {}); toast('Thank-you award sent', 'success'); drawAuto(main, body); }
        catch (err) { sb.disabled = false; toast((err && err.message) || 'Could not send it. Try again in a moment.', 'error'); }
        return;
      }
      if (kb) {
        var kid = kb.getAttribute('data-skip');
        var goSkip = async function () {
          try { await Api.post('/educator-points/auto/drafts/' + kid + '/skip', {}); toast('Skipped', 'success'); drawAuto(main, body); }
          catch (err) { toast((err && err.message) || 'Could not skip it. Try again in a moment.', 'error'); }
        };
        if (KT.confirm) { KT.confirm({ title: 'Skip this award?', description: 'Nothing is sent to them for this month.', okLabel: 'Skip' }).then(function (ok) { if (ok) { goSkip(); } }); } else { goSkip(); }
        return;
      }
      if (all) {
        var n = drafts.length;
        var goAll = async function () {
          all.disabled = true;
          try {
            for (var i = 0; i < drafts.length; i++) { await saveDraft(drafts[i].id); }
            var r = await Api.post('/educator-points/auto/send-all', {});
            toast(r.sent + ' thank-you award' + (r.sent === 1 ? '' : 's') + ' sent' + (r.failed ? ', ' + r.failed + ' could not be emailed' : ''), r.failed ? 'warning' : 'success');
            drawAuto(main, body);
          } catch (err) { all.disabled = false; toast((err && err.message) || 'Could not send them. Try again in a moment.', 'error'); }
        };
        if (KT.confirm) { KT.confirm({ title: 'Send all ' + n + ' thank-you awards?', description: 'Each educator gets their certificate email with the letter as you left it.', okLabel: 'Send all' }).then(function (ok) { if (ok) { goAll(); } }); } else { goAll(); }
      }
    };
    if (first) { body.addEventListener('click', function (e) { return body.__taClick(e); }); }
  }

  /* SETTINGS → EDUCATOR AWARDS (2026-11-02). The automatic award controls: on/off, review or send, the schedule, the
     reward (a signed thank-you certificate: letter tone, certificate message, PDF), who qualifies and who is told.
     Same store as before (ThankYouAuto::settings / save via GET/PUT /educator-points/auto). Agency admins edit; a
     centre director sees them read-only (the server returns can_edit:false and no staff names). */
  async function drawAutoSettings(main, body) {
    var d;
    try { d = await Api.get('/educator-points/auto'); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="padding:16px;">' + esc((e && e.message) || 'Could not load the automatic award settings. Try again in a moment.') + '</div>'; return; }
    var s = d.settings, edit = d.can_edit !== false, dis = edit ? '' : ' disabled';
    var SM = INPUT + 'width:84px;text-align:right;';
    var sel = function (id, opts, val, w) {
      return '<select id="' + id + '" data-kt-nofit="1"' + dis + ' style="' + INPUT + 'width:' + (w || 220) + 'px;min-width:0;max-width:100%;">' + opts.map(function (o) {
        return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>';
    };
    var row = function (label, html, hint) {
      return '<div style="margin-bottom:12px;"><div style="font-size:12px;font-weight:800;letter-spacing:.3px;color:#475569;margin-bottom:5px;">' + label + '</div>'
        + '<div data-kt-nopack="1" data-kt-nofb="1" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:13px;color:#334155;">' + html + '</div>'
        + (hint ? '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">' + hint + '</div>' : '') + '</div>';
    };
    var chk = function (id, on, label) { return '<label style="display:inline-flex;gap:6px;align-items:center;font-size:13px;"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + dis + '> ' + label + '</label>'; };
    var roleBox = function (r, label) { return '<label style="display:inline-flex;gap:6px;align-items:center;font-size:13px;margin-right:10px;"><input type="checkbox" data-role="' + r + '"' + (s.roles.indexOf(r) !== -1 ? ' checked' : '') + dis + '> ' + label + '</label>'; };
    var excl = (d.staff || []).map(function (p) {
      return '<label style="display:flex;gap:8px;align-items:center;font-size:13px;padding:3px 0;"><input type="checkbox" data-excl="' + p.user_id + '"' + (s.exclude.indexOf(p.user_id) !== -1 ? ' checked' : '') + dis + '> ' + esc(p.name) + ' <span style="color:#94A3B8;font-size:11.5px;">' + esc(roleLabel(p.role)) + '</span></label>';
    }).join('');
    var mgr = (d.managers || []).map(function (m) { return esc(m.first); }).filter(Boolean);

    var prev = d.preview || [];
    var previewHtml = edit ? '<div style="' + CARD + 'margin-bottom:12px;"><div style="font-weight:800;color:#0F172A;margin-bottom:4px;">👀 Who would qualify for ' + esc(d.preview_month_label) + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-bottom:8px;">With the settings below (save to update). ' + esc(d.rule_label.charAt(0).toUpperCase() + d.rule_label.slice(1)) + '.</div>'
      + (prev.length ? '<div style="display:flex;flex-wrap:wrap;gap:6px;">' + prev.map(function (p) {
          return '<span style="display:inline-flex;gap:6px;align-items:center;border:1px solid #E2E8F0;border-radius:999px;padding:4px 10px;font-size:12.5px;background:#F8FAFC;"><strong style="color:#0F172A;">' + esc(p.name) + '</strong><span style="color:#64748B;">' + num(p.points) + ' pts</span></span>';
        }).join('') + '</div>' : '<div style="font-size:13px;color:#64748B;">Nobody yet with these settings.</div>') + '</div>' : '';

    var form = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:12px;">'
      + '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:10px;">🗓 When and how</div>'
      + row('RUNS', sel('ta-when', [['day', 'On a day of the month'], ['first_weekday', 'On the first weekday'], ['last', 'On the last day']], s.when)
          + '<span id="ta-dayw">day <input type="text" inputmode="numeric" data-kt-nofit="1" id="ta-day" value="' + s.day + '"' + dis + ' style="' + SM + 'width:60px;"></span>'
          + '<span>at <input type="time" data-kt-nofit="1" id="ta-time" value="' + esc(s.time) + '"' + dis + ' style="' + INPUT + 'width:140px;"></span>', 'Your agency’s time. Each run thanks people for the month before.')
      + row('MODE', '<label style="display:flex;gap:6px;align-items:center;"><input type="radio" name="ta-mode" value="review"' + (s.mode !== 'auto' ? ' checked' : '') + dis + '> Prepare them for review first</label>'
          + '<label style="display:flex;gap:6px;align-items:center;"><input type="radio" name="ta-mode" value="auto"' + (s.mode === 'auto' ? ' checked' : '') + dis + '> Send automatically</label>',
          'Review: drafts wait below on this page until an admin sends them.')
      + '<div id="ta-expw">' + row('DRAFTS EXPIRE AFTER', '<input type="text" inputmode="numeric" data-kt-nofit="1" id="ta-exp" value="' + s.expire_days + '"' + dis + ' style="' + SM + 'width:60px;"> days', 'A draft nobody sends by then is dropped; nothing is sent.') + '</div>'
      + '</div>'
      + '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:10px;">🏅 The reward</div>'
      + row('AWARD', '<span>A thank-you certificate by email, with a personal letter</span>', 'Bonuses, payouts and gift cards are always given by hand under Educator rewards → Points & awards.')
      + row('SIGNED BY', sel('ta-signer', (d.signers || []).map(function (x) { return [x.key, x.first + ' · ' + x.title]; }), s.signer || ((d.signers || [])[0] || {}).key))
      + row('LETTER', sel('ta-tone', [['warm', 'Warm'], ['formal', 'Formal'], ['short', 'Short']], s.tone, 140), d.ai_available ? '✨ Each letter is written for that educator from their own month, and can be edited before it goes out (review mode).' : 'A written letter from their month (the AI Bundle writes a personal one).')
      + row('CERTIFICATE MESSAGE', '<input type="text" id="ta-msg" value="' + esc(s.message) + '"' + dis + ' placeholder="Optional, e.g. Thank you for everything you do" style="' + INPUT + 'width:100%;">')
      + row('ATTACH', chk('ta-pdf', s.pdf, 'The PDF certificate'))
      + '</div>'
      + '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:10px;">⭐ Who qualifies</div>'
      + row('RULE', sel('ta-rule', [['min_points', 'Everyone with at least…'], ['top', 'The top earners'], ['all_active', 'Everyone with points']], s.rule)
          + '<span id="ta-minw"><input type="text" inputmode="numeric" data-kt-nofit="1" id="ta-min" value="' + s.min_points + '"' + dis + ' style="' + SM + '"> points</span>'
          + '<span id="ta-topw">top <input type="text" inputmode="numeric" data-kt-nofit="1" id="ta-top" value="' + s.top_n + '"' + dis + ' style="' + SM + 'width:60px;"></span>'
          + '<span id="ta-floorw">with at least <input type="text" inputmode="numeric" data-kt-nofit="1" id="ta-floor" value="' + s.floor + '"' + dis + ' style="' + SM + '"> pts</span>')
      + row('ROLES', roleBox('educator', 'Educators') + roleBox('home_visitor', 'Home visitors') + roleBox('centre_director', 'Directors'))
      + (edit ? row('LEAVE OUT', '<details style="width:100%;"><summary style="cursor:pointer;font-size:13px;color:#13708A;font-weight:700;">' + (s.exclude.length ? s.exclude.length + ' left out' : 'Nobody left out') + ' · choose</summary><div style="max-height:220px;overflow:auto;margin-top:6px;" data-kt-scroll="1">' + (excl || '<div style="color:#94A3B8;font-size:12.5px;">No staff earn points yet.</div>') + '</div></details>') : '')
      + '</div>'
      + '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:10px;">📣 Who is told</div>'
      + row('THE EDUCATOR', '<span>Their certificate email, a bell notice and a push to their phone</span>')
      + row('COPY', chk('ta-bcc', s.bcc_managers, 'Copy the agency admins and their director (hidden BCC)'))
      + row('WHEN DRAFTS ARE READY', '<span>' + (mgr.length ? 'Agency admin' + (mgr.length === 1 ? ' ' : 's ') + mgr.join(', ') + (mgr.length === 1 ? ' gets' : ' get') : (edit ? 'No agency admin with an email address gets' : 'The agency admins get')) + ' a bell and an email (review mode)</span>')
      + '</div></div>'
      + (edit ? '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;"><span id="ta-msgline" style="align-self:center;font-size:12.5px;"></span><button type="button" id="ta-save" class="kt-btn kt-btn-primary">Save automatic awards</button></div>'
        : '<div style="font-size:12.5px;color:#64748B;margin-top:10px;">Only an agency admin can change these.</div>');

    body.innerHTML = '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;font-size:12.5px;">'
      + '<a href="#educator-rewards" style="color:#0E7C90;font-weight:700;text-decoration:none;">⭐ Educator rewards</a><span style="color:#94A3B8;">·</span>'
      + '<a href="#educator-rewards?tab=history" style="color:#0E7C90;font-weight:700;text-decoration:none;">🗂 Award history</a>'
      + (edit ? '<span style="color:#94A3B8;">·</span><button type="button" data-kt-jump="1" style="border:0;background:none;padding:0;font:inherit;color:#0E7C90;font-weight:700;cursor:pointer;">✉️ Drafts to review</button>' : '') + '</div>'
      + autoStatusCard(d, s, true, edit) + previewHtml + form;
    var q = function (x) { return body.querySelector(x); };
    var jump = q('[data-kt-jump]');
    if (jump) { jump.addEventListener('click', function () { var t = document.getElementById('eas-review'); if (t) { t.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }); }
    function sync() {
      var w = q('#ta-when').value, r = q('#ta-rule').value, m = body.querySelector('input[name="ta-mode"]:checked');
      q('#ta-dayw').style.display = w === 'day' ? '' : 'none';
      q('#ta-minw').style.display = r === 'min_points' ? '' : 'none';
      q('#ta-topw').style.display = r === 'top' ? '' : 'none';
      q('#ta-floorw').style.display = r === 'min_points' ? 'none' : '';
      q('#ta-expw').style.display = m && m.value === 'auto' ? 'none' : '';
    }
    q('#ta-when').addEventListener('change', sync); q('#ta-rule').addEventListener('change', sync);
    body.querySelectorAll('input[name="ta-mode"]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
    if (!edit) { return; }
    function payload() {
      var mode = body.querySelector('input[name="ta-mode"]:checked');
      return { enabled: q('#ta-en').checked, when: q('#ta-when').value, day: parseInt(q('#ta-day').value, 10) || 1, time: q('#ta-time').value || '09:00',
        rule: q('#ta-rule').value, min_points: parseInt(q('#ta-min').value, 10) || 1, top_n: parseInt(q('#ta-top').value, 10) || 3, floor: parseInt(q('#ta-floor').value, 10) || 1,
        roles: Array.prototype.map.call(body.querySelectorAll('[data-role]:checked'), function (i) { return i.getAttribute('data-role'); }),
        exclude: Array.prototype.map.call(body.querySelectorAll('[data-excl]:checked'), function (i) { return parseInt(i.getAttribute('data-excl'), 10); }),
        signer: q('#ta-signer') ? q('#ta-signer').value : null, tone: q('#ta-tone').value, message: q('#ta-msg').value,
        mode: mode ? mode.value : 'review', pdf: q('#ta-pdf').checked, bcc_managers: q('#ta-bcc').checked,
        expire_days: Math.max(3, Math.min(60, parseInt(q('#ta-exp').value, 10) || 14)) };
    }
    async function save(btn) {
      var msg = q('#ta-msgline'), p = payload();
      if (!p.roles.length) { msg.style.color = '#B91C1C'; msg.textContent = 'Pick at least one role.'; return; }
      if (btn) { btn.disabled = true; } msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      try { await Api.put('/educator-points/auto', p); toast('Automatic awards saved', 'success'); drawAutoSettings(main, body); }
      catch (err) { if (btn) { btn.disabled = false; } msg.style.color = '#B91C1C'; msg.textContent = (err && err.message) || 'Could not save. Try again in a moment.'; }
    }
    q('#ta-save').addEventListener('click', function (e) { save(e.currentTarget); });
    q('#ta-en').addEventListener('change', function () { save(null); });
  }

  /* SETTINGS → EDUCATOR AWARDS (2026-11-02). Every educator award setting, in the Settings section beside Clock
     settings: Automatic awards (schedule, reward, who qualifies, who is told, and the drafts to review), Point values &
     rewards (points per activity, daily limits, suggested amount, the Award dialog's default method, who approves),
     Same stores and endpoints as before. Agency admins edit; a centre director reads.
     GIFT CARD ACCOUNTS ARE IN PAYMENT PROVIDERS (2026-11-02, Anthony: "move the giftcard settings to payment providers
     as new subtabs"): Settings → Payment providers → 🎁 Gift cards (the agency's Giftbit / Tango Card accounts) and,
     for the superadmin, 🛠 KiddieTrac gift card accounts. This screen keeps only the award choices (default method,
     suggested amount) with a link there; an old ?tab=gifts|provider link lands on the matching Payment providers tab. */
  var easTab = 'auto';
  async function renderAwardSettings(main) {
    var fresh = !main.querySelector('#eas-root');
    var root = screenRoot(main, 'eas-root', 0);
    var m = fresh && /[?&]tab=(auto|points|gifts|provider)\b/.exec(location.hash);
    if (m && (m[1] === 'gifts' || m[1] === 'provider')) { location.replace('#payment-providers?tab=giftcards'); return; }
    if (m) { easTab = m[1]; }
    var list = [['auto', '🏅 Automatic awards'], ['points', '⚙️ Point values & rewards']];
    if (!list.some(function (t) { return t[0] === easTab; })) { easTab = 'auto'; }
    var tabs = root.querySelector('#eas-tabs');
    if (!tabs) { tabs = document.createElement('div'); tabs.id = 'eas-tabs'; root.insertBefore(tabs, root.firstChild); }
    tabs.innerHTML = '<div role="tablist" aria-label="Educator award settings" style="display:flex;margin-bottom:12px;">' + list.map(function (t) {
      return '<button type="button" role="tab" aria-selected="' + (easTab === t[0] ? 'true' : 'false') + '" data-eastab="' + t[0] + '">' + t[1] + '</button>';
    }).join('') + '</div>';
    tabs.querySelectorAll('[data-eastab]').forEach(function (b) { b.addEventListener('click', function () { easTab = b.getAttribute('data-eastab'); renderAwardSettings(main); }); });
    var old = root.querySelector('#eas-drafts'); if (old) { old.remove(); }
    var body = freshBody(root, 'eas-body');
    if (easTab === 'points') { return drawSettings(main, body); }
    await drawAutoSettings(main, body);
    // Admins: the drafts waiting for review and what was sent, under the settings (one place for automatic awards).
    if (!isAgencyAdmin() || easTab !== 'auto') { return; }
    var box = document.createElement('div'); box.id = 'eas-drafts'; root.appendChild(box);
    await drawAuto(main, box);
  }

  /* AWARD HISTORY (2026-11-02, Anthony: "where is the subtab to show who got awarded for the month(s) - keep a
     history"). GET /educator-points/history (App\Support\EducatorAwardHistory): every award ever made, grouped by
     month, newest first, from the stored rows (points, amount and people as they were then; departed staff stay).
     One table, so the house export bar (CSV / Excel / PDF) exports all of it; month rows are group headings. */
  var hist = { from: '', to: '', centre: '', user: '', cancelled: false };
  async function drawHistory(main, body) {
    var qs = [];
    if (hist.from) { qs.push('from=' + hist.from); }
    if (hist.to) { qs.push('to=' + hist.to); }
    if (hist.centre) { qs.push('centre_id=' + hist.centre); }
    if (hist.user) { qs.push('user_id=' + hist.user); }
    if (hist.cancelled) { qs.push('cancelled=1'); }
    var d;
    try { d = await Api.get('/educator-points/history' + (qs.length ? '?' + qs.join('&') : '')); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="padding:16px;">' + esc((e && e.message) || 'Could not load the award history. Try again in a moment.') + '</div>'; return; }
    var rows = d.rows || [];
    var months = [], byMonth = {};
    rows.forEach(function (r) { if (!byMonth[r.month]) { byMonth[r.month] = []; months.push(r.month); } byMonth[r.month].push(r); });
    var money0 = rows.reduce(function (a, r) { return a + (Number(r.amount) || 0); }, 0);
    var ppl = {}; rows.forEach(function (r) { ppl[r.user_id] = 1; });
    var nPeople = Object.keys(ppl).length;
    var FL = 'display:flex;flex-direction:column;gap:3px;font-size:11px;font-weight:800;color:#64748B;letter-spacing:.3px;';
    var filters = '<div data-kt-nopack="1" data-kt-nofb="1" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;">'
      + '<label style="' + FL + '">FROM' + monthInput('rh-from', hist.from) + '</label>'
      + '<label style="' + FL + '">TO' + monthInput('rh-to', hist.to) + '</label>'
      + ((d.centres || []).length > 1 ? '<label style="' + FL + '">CENTRE<select id="rh-centre" data-kt-nofit="1" style="' + INPUT + 'width:200px;min-width:0;max-width:100%;"><option value="">All centres</option>'
        + d.centres.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(hist.centre) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></label>' : '')
      + '<label style="' + FL + '">EDUCATOR<select id="rh-user" data-kt-nofit="1" style="' + INPUT + 'width:200px;min-width:0;max-width:100%;"><option value="">Everyone</option>'
        + (d.people || []).map(function (p) { return '<option value="' + p.user_id + '"' + (String(p.user_id) === String(hist.user) ? ' selected' : '') + '>' + esc(p.name) + '</option>'; }).join('')
        + (hist.user && !(d.people || []).some(function (p) { return String(p.user_id) === String(hist.user); }) ? '<option value="' + esc(hist.user) + '" selected>Selected person</option>' : '') + '</select></label>'
      + '<label style="display:inline-flex;gap:6px;align-items:center;font-size:12.5px;color:#475569;height:30px;"><input type="checkbox" id="rh-cx" data-kt-noswitch="1"' + (hist.cancelled ? ' checked' : '') + '> Include cancelled</label>'
      + ((hist.from || hist.to || hist.centre || hist.user || hist.cancelled) ? '<button type="button" id="rh-reset" class="kt-btn" style="height:30px;">Clear filters</button>' : '')
      + '</div>';
    var summary = '<div style="font-size:13px;color:#475569;margin-bottom:8px;"><b>' + rows.length + '</b> award' + (rows.length === 1 ? '' : 's') + ' to <b>' + nPeople + '</b> ' + (nPeople === 1 ? 'person' : 'people')
      + ' across <b>' + months.length + '</b> month' + (months.length === 1 ? '' : 's') + (money0 ? ' · <b>' + money(money0) + '</b> in bonuses, payouts and gift cards' : '') + '. Dates in your agency’s time.</div>';
    function approver(r) {
      if (r.source === 'auto') { return r.approved_by ? esc(r.approved_by) + ' <span style="color:#94A3B8;">(reviewed)</span>' : '<span style="color:#64748B;">No review (sent on schedule)</span>'; }
      return r.approved_by ? esc(r.approved_by) : '<span style="color:#CBD5E1;">—</span>';
    }
    function rewardOf(r) { return r.method === 'thank_you' ? 'Certificate' : money(r.amount, r.currency); }
    var COLS = 9;
    var table = '';
    if (!rows.length) {
      table = '<div class="kt-card" data-kt-empty="1" style="padding:28px 16px;text-align:center;color:#64748B;">'
        + '<div style="font-size:30px;line-height:1;margin-bottom:6px;">🗂</div>'
        + '<div style="font-weight:800;color:#0F172A;margin-bottom:3px;">' + (qs.length ? 'No awards match these filters' : 'No awards yet') + '</div>'
        + '<div style="font-size:13px;">' + (qs.length ? 'Try a wider month range or clear the filters.' : 'Every bonus, payout, gift card and thank-you award given from Points & awards, or sent automatically, is kept here for good, month by month.') + '</div></div>';
    } else {
      table = '<div class="kt-card" style="padding:0;overflow:auto;" data-kt-scroll="1"><table id="rh-table" data-kt-no-filter="1" data-kt-no-bulk="1" data-kt-export-title="Educator award history" style="width:100%;">'
        + '<thead><tr><th style="min-width:190px;">Educator</th><th style="min-width:170px;">Award</th><th style="text-align:right;">Points</th><th style="text-align:right;">Reward</th><th style="min-width:110px;">Method</th><th style="min-width:130px;">Approved by</th><th style="white-space:nowrap;">Date</th><th>How</th><th>Status</th></tr></thead><tbody>'
        + months.map(function (m) {
          var list = byMonth[m];
          var mm = list.reduce(function (a, r) { return a + (Number(r.amount) || 0); }, 0);
          return '<tr class="rh-month"><td colspan="' + COLS + '" style="background:#F1F5F9;font-weight:800;color:#0F172A;font-size:13px;padding:7px 12px;">' + esc(list[0].month_label)
            + ' <span style="font-weight:600;color:#64748B;font-size:12px;">· ' + list.length + ' award' + (list.length === 1 ? '' : 's') + (mm ? ' · ' + money(mm) : '') + '</span></td></tr>'
            + list.map(function (r) {
              return '<tr' + (r.status === 'cancelled' ? ' style="opacity:.6;"' : '') + '><td><strong>' + esc(r.name) + '</strong>'
                + (r.left ? ' <span style="font-size:10.5px;font-weight:800;color:#475569;background:#F1F5F9;border-radius:999px;padding:1px 7px;">No longer on staff</span>' : '')
                + '<div style="font-size:11.5px;color:#94A3B8;">' + esc(roleLabel(r.role || '')) + (r.centres.length ? ' · ' + esc(r.centres.join(', ')) : '') + '</div></td>'
                + '<td>' + (r.method === 'thank_you' ? '🏅 ' : '') + esc(r.method_label) + (r.brand ? ' <span style="color:#64748B;">(' + esc(r.brand) + ')</span>' : '')
                + (r.note ? '<div style="font-size:11.5px;color:#64748B;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(r.note) + '">“' + esc(r.note) + '”</div>' : '') + '</td>'
                + '<td style="text-align:right;font-weight:700;">' + num(r.points) + '</td>'
                + '<td style="text-align:right;white-space:nowrap;">' + esc(rewardOf(r)) + '</td>'
                + '<td>' + esc(r.kind) + '</td>'
                + '<td>' + approver(r) + '</td>'
                + '<td style="white-space:nowrap;"><span data-export="' + esc(r.date_full || '') + '">' + esc(r.date || '') + '</span></td>'
                + '<td><span style="font-size:11px;font-weight:800;border-radius:999px;padding:2px 8px;' + (r.source === 'auto' ? 'color:#5B21B6;background:#EDE9FE;' : 'color:#0F172A;background:#F1F5F9;') + '">' + (r.source === 'auto' ? 'Automatic' : 'Manual') + '</span></td>'
                + '<td>' + pill(r.status) + '</td></tr>';
            }).join('');
        }).join('') + '</tbody></table></div>';
    }
    body.innerHTML = autoLine(d.auto, d.can_edit_auto) + filters + summary + table
      + '<div style="font-size:11.5px;color:#94A3B8;margin-top:8px;">Points and amounts are as they were when each award was made. Gift card codes are never shown here: only the educator sees their card.</div>';

    var t = body.querySelector('#rh-table');
    if (t) {
      t.__ktRecordTotal = rows.length;   // the bar counts awards, not the month heading rows
      // The export: every award, one row each, with the columns a spreadsheet wants (the month headings are on screen only).
      t.__ktExportAll = function () {
        return {
          headers: ['Month', 'Educator', 'Role', 'Centres', 'Award', 'Brand', 'Points', 'Amount', 'Currency', 'Method', 'Approved by', 'Date (' + (d.tz || 'agency time') + ')', 'Automatic or manual', 'Status', 'Note'],
          rows: rows.map(function (r) {
            return [r.month_label, r.name, roleLabel(r.role || ''), r.centres.join('; '), r.method_label, r.brand || '', String(r.points), r.method === 'thank_you' ? '' : (Number(r.amount) || 0).toFixed(2),
              r.method === 'thank_you' ? '' : (r.currency || ''), r.kind, r.source === 'auto' ? (r.approved_by ? r.approved_by + ' (reviewed)' : 'No review (sent on schedule)') : (r.approved_by || ''),
              r.date_full || '', r.source === 'auto' ? 'Automatic' : 'Manual', ((STATUS[r.status] || [r.status])[0]), r.note || ''];
          })
        };
      };
    }
    function on(id, fn) { var el = body.querySelector('#' + id); if (el) { el.addEventListener('change', fn); } }
    on('rh-from', function (e) { var v = e.target.value; if (!v || /^\d{4}-\d{2}$/.test(v)) { hist.from = v; drawHistory(main, body); } });
    on('rh-to', function (e) { var v = e.target.value; if (!v || /^\d{4}-\d{2}$/.test(v)) { hist.to = v; drawHistory(main, body); } });
    on('rh-centre', function (e) { hist.centre = e.target.value; drawHistory(main, body); });
    on('rh-user', function (e) { hist.user = e.target.value; drawHistory(main, body); });
    on('rh-cx', function (e) { hist.cancelled = e.target.checked; drawHistory(main, body); });
    var rs = body.querySelector('#rh-reset');
    if (rs) { rs.addEventListener('click', function () { hist = { from: '', to: '', centre: '', user: '', cancelled: false }; drawHistory(main, body); }); }
  }

  async function drawSettings(main, body) {
    var d;
    try { d = await Api.get('/educator-points/settings'); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="padding:16px;">' + esc((e && e.message) || 'Could not load') + '</div>'; return; }
    var s = d.settings, edit = d.can_edit, dis = edit ? '' : ' disabled';
    var SM = INPUT + 'width:80px;text-align:right;';
    var h = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:12px;">'
      + '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:4px;">⭐ Points per activity</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin-bottom:8px;">Points for each one logged, and the most that count in one day (0 points = not counted; 0 limit = no limit).</div>'
      + '<table style="width:100%;font-size:13px;" data-kt-noexport="1" data-kt-no-filter="1" data-kt-no-bulk="1"><thead><tr><th style="text-align:left;">Activity</th><th style="text-align:right;">Points</th><th style="text-align:right;">Daily limit</th></tr></thead><tbody>'
      + d.activities.map(function (a) {
        return '<tr><td>' + a.icon + ' ' + esc(a.label) + '</td>'
          + '<td style="text-align:right;"><input type="text" inputmode="numeric" data-kt-nofit="1" data-pts="' + a.key + '" value="' + a.points + '" style="' + SM + '"' + dis + '></td>'
          + '<td style="text-align:right;"><input type="text" inputmode="numeric" data-kt-nofit="1" data-cap="' + a.key + '" value="' + a.cap + '" style="' + SM + '"' + dis + '></td></tr>';
      }).join('') + '</tbody></table></div>'
      + '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:8px;">⚙️ Rewards</div>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:8px;"><input type="checkbox" id="st-en"' + (s.enabled ? ' checked' : '') + dis + '> Points are on (educators see My points)</label>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:12px;"><input type="checkbox" id="st-lb"' + (s.leaderboard ? ' checked' : '') + dis + '> Show a leaderboard of points at each centre (never money)</label>'
      + '<div style="font-size:13px;color:#334155;margin-bottom:4px;">Suggested award</div>'
      + '<div data-kt-nopack="1" data-kt-nofb="1" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:13px;">$ <input type="text" inputmode="decimal" data-kt-nofit="1" id="st-ra" value="' + s.rate_amount + '" style="' + SM + '"' + dis + '> per '
      + '<input type="text" inputmode="numeric" data-kt-nofit="1" id="st-rp" value="' + s.rate_points + '" style="' + SM + '"' + dis + '> points '
      + '<select id="st-cur" data-kt-nofit="1" style="' + INPUT + 'width:84px;min-width:84px;"' + dis + '><option' + (s.currency === 'CAD' ? ' selected' : '') + '>CAD</option><option' + (s.currency === 'USD' ? ' selected' : '') + '>USD</option></select></div>'
      + '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">$0 = no suggestion; directors type the amount each time.</div>'
      + '<div style="font-size:13px;color:#334155;margin:12px 0 4px;">Award dialog starts on</div>'
      + '<select id="st-dm" data-kt-nofit="1" style="' + INPUT + 'width:260px;min-width:0;max-width:100%;"' + dis + '>'
      + [['payroll_bonus', 'Bonus in the next pay run'], ['payout', 'Payout (paid outside KiddieTrac)'], ['gift_card_manual', 'Gift card you bought'], ['gift_card_provider', 'Gift card service (Giftbit / Tango Card)']].map(function (o) {
          return '<option value="' + o[0] + '"' + (s.default_method === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>'
      + '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">Whoever gives the award can still pick another way.</div>'
      + (isAgencyAdmin() ? '<div style="margin-top:10px;font-size:12.5px;"><a href="#payment-providers?tab=giftcards" style="color:#0E7C90;font-weight:700;text-decoration:none;">🎁 Gift card provider · Payment providers</a>'
          + '<span style="color:#94A3B8;"> (Giftbit / Tango Card accounts, test or live)</span></div>' : '')
      + '</div>'
      + '<div style="' + CARD + '"><div style="font-weight:800;color:#0F172A;margin-bottom:8px;">🧑‍⚖️ Who gives awards, and who is told</div>'
      + '<div style="font-size:13px;color:#334155;line-height:1.55;">'
      + '<div><b>Agency admins</b> give awards to anyone, directors included.</div>'
      + '<div><b>Centre directors</b> give awards to the educators and home visitors at their own centres (not to themselves or another director).</div>'
      + '<div style="margin-top:8px;"><b>Told:</b> the educator gets an in-app notice, a push and an email; the agency admins and the director(s) of their centre get a hidden copy. A gift card itself is never in the email.</div>'
      + '<div style="margin-top:8px;color:#64748B;font-size:12px;">Automatic thank-you awards have their own schedule and recipients under the Automatic awards tab.</div></div></div></div>'
      + (edit ? '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;"><span id="st-msg" style="align-self:center;font-size:12.5px;"></span><button type="button" id="st-save" class="kt-btn kt-btn-primary">Save point values</button></div>'
        : '<div style="font-size:12.5px;color:#64748B;margin-top:10px;">Only an agency admin can change these.</div>');
    body.innerHTML = h;
    if (!edit) { return; }
    body.querySelector('#st-save').addEventListener('click', async function (e) {
      var btn = e.currentTarget, msg = body.querySelector('#st-msg');
      var p = { enabled: body.querySelector('#st-en').checked, leaderboard: body.querySelector('#st-lb').checked,
        rate_amount: parseFloat(body.querySelector('#st-ra').value) || 0, rate_points: parseInt(body.querySelector('#st-rp').value, 10) || 100,
        currency: body.querySelector('#st-cur').value, default_method: body.querySelector('#st-dm').value, points: {}, caps: {} };
      body.querySelectorAll('[data-pts]').forEach(function (i) { p.points[i.getAttribute('data-pts')] = parseInt(i.value, 10) || 0; });
      body.querySelectorAll('[data-cap]').forEach(function (i) { p.caps[i.getAttribute('data-cap')] = parseInt(i.value, 10) || 0; });
      btn.disabled = true; msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      try { await Api.put('/educator-points/settings', p); toast('Point values saved', 'success'); drawSettings(main, body); }
      catch (err) { btn.disabled = false; msg.style.color = '#B91C1C'; msg.textContent = (err && err.message) || 'Could not save'; }
    });
  }

  /* This agency's own Giftbit account. Each agency funds its own cards, so the token is
     entered here by the agency admin; it is stored encrypted and only ever shown masked. */
  async function drawGiftbitBox(main, body) {
    var c;
    try { c = await Api.get('/educator-points/giftbit'); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="padding:16px;">' + esc((e && e.message) || 'Could not load') + '</div>'; return; }
    var F = INPUT + 'display:block;width:100% !important;max-width:100% !important;';
    var badge = c.connected
      ? '<span style="font-size:12px;font-weight:800;color:#065F46;background:#D1FAE5;border-radius:999px;padding:2px 10px;">Connected · ' + esc(c.using_env) + '</span>'
      : '<span style="font-size:12px;font-weight:800;color:#92400E;background:#FEF3C7;border-radius:999px;padding:2px 10px;">Not connected</span>';
    body.innerHTML = '<div style="' + CARD + 'max-width:none;">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;"><div style="font-weight:800;color:#0F172A;">🎁 Giftbit gift cards</div>' + badge + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin:6px 0 10px;line-height:1.5;">Send digital gift cards (Amazon, Tim Hortons and hundreds more) as educator rewards. '
      + 'Cards are bought from <b>your agency\u2019s own Giftbit account</b>, so you fund them there. Create an account at giftbit.com, add funds, then paste an API token from <i>Account → API keys</i>. '
      + 'Start with a <b>testbed</b> token (free, no real cards), then switch to a live token.'
      + '</div>'
      + '<label style="display:block;font-size:12px;font-weight:700;color:#475569;">Environment</label><select id="gb-env" data-kt-nofit="1" style="' + F + 'margin:4px 0 10px;"><option value="testbed"' + (c.env !== 'live' ? ' selected' : '') + '>Testbed (no real cards)</option><option value="live"' + (c.env === 'live' ? ' selected' : '') + '>Live</option></select>'
      + '<label style="display:block;font-size:12px;font-weight:700;color:#475569;">API token</label><input id="gb-tok" type="password" data-kt-nofit="1" autocomplete="off" placeholder="' + (c.token_mask ? 'Saved (' + esc(c.token_mask) + '). Paste a new token to replace it.' : 'Paste your Giftbit API token') + '" style="' + F + 'margin:4px 0 10px;">'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:10px;"><input type="checkbox" id="gb-en"' + (c.enabled ? ' checked' : '') + '> Use Giftbit for educator rewards</label>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;"><span id="gb-msg" style="align-self:center;font-size:12.5px;"></span>'
      + (c.token_mask ? '<button type="button" id="gb-clear" class="kt-btn">Remove token</button>' : '')
      + (c.connected ? '<button type="button" id="gb-test" class="kt-btn">Test connection</button>' : '')
      + '<button type="button" id="gb-save" class="kt-btn kt-btn-primary">Save</button></div></div>';
    var msg = body.querySelector('#gb-msg');
    function save(p) {
      msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      return Api.put('/educator-points/giftbit', p).then(function () { toast('Giftbit settings saved', 'success'); drawGiftbitBox(main, body); })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    }
    body.querySelector('#gb-save').addEventListener('click', function () {
      var p = { env: body.querySelector('#gb-env').value, enabled: body.querySelector('#gb-en').checked };
      var t = body.querySelector('#gb-tok').value.trim(); if (t) { p.token = t; }
      save(p);
    });
    var cl = body.querySelector('#gb-clear');
    if (cl) { cl.addEventListener('click', function () { save({ clear_token: true }); }); }
    var tb = body.querySelector('#gb-test');
    if (tb) {
      tb.addEventListener('click', async function () {
        msg.style.color = '#64748B'; msg.textContent = 'Testing…';
        try { var r = await Api.post('/educator-points/giftbit/test', {}); msg.style.color = r.ok ? '#065F46' : '#B91C1C'; msg.textContent = r.message; }
        catch (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Test failed'; }
      });
    }
  }

  /* GIFT CARD ACCOUNTS (2026-10-20): Giftbit and Tango Card side by side. Each card is drawn
     into its own box and redraws only itself after a save. */
  function twoBoxes(body) {
    body.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:12px;align-items:start;max-width:1300px;">'
      + '<div id="gc-gb"></div><div id="gc-tg"></div></div>';
    return [body.querySelector('#gc-gb'), body.querySelector('#gc-tg')];
  }
  function drawGiftbit(main, body) {
    var b = twoBoxes(body);
    drawGiftbitBox(main, b[0]);
    drawTangoBox(main, b[1]);
  }

  /* Tango Card: the platform name + API key (Tango portal → API keys), and the customer and
     account the cards are paid from. The key is stored encrypted and only shown masked. */
  async function drawTangoBox(main, box) {
    var url = '/educator-points/tango';
    var c;
    try { c = await Api.get(url); }
    catch (e) { box.innerHTML = '<div class="kt-card" style="padding:16px;">' + esc((e && e.message) || 'Could not load') + '</div>'; return; }
    var F = INPUT + 'display:block;width:100% !important;max-width:100% !important;';
    var Lb = 'display:block;font-size:12px;font-weight:700;color:#475569;';
    var badge = c.connected
      ? '<span style="font-size:12px;font-weight:800;color:#065F46;background:#D1FAE5;border-radius:999px;padding:2px 10px;">Connected · ' + esc(c.using_env || c.env) + '</span>'
      : '<span style="font-size:12px;font-weight:800;color:#92400E;background:#FEF3C7;border-radius:999px;padding:2px 10px;">Not connected</span>';
    var field = function (id, label, val, ph) {
      return '<label style="' + Lb + '">' + label + '</label><input id="tg-' + id + '" type="text" data-kt-nofit="1" autocomplete="off" value="' + esc(val || '') + '" placeholder="' + esc(ph) + '" style="' + F + 'margin:4px 0 10px;">';
    };
    box.innerHTML = '<div style="' + CARD + '">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;"><div style="font-weight:800;color:#0F172A;">' + '🎁 Tango Card gift cards' + '</div>' + badge + '</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin:6px 0 10px;line-height:1.5;">'
      + 'Send digital gift cards from Tango Card’s catalogue as educator rewards, paid from <b>your agency’s own Tango account</b>. In the Tango portal, open <i>API keys → Create credentials</i> for the platform name and API key; the customer and account identifiers are on your Tango account. Start in the <b>sandbox</b> (no real cards).'
      + '</div>'
      + '<label style="' + Lb + '">Environment</label><select id="tg-env" data-kt-nofit="1" style="' + F + 'margin:4px 0 10px;"><option value="sandbox"' + (c.env !== 'production' ? ' selected' : '') + '>Sandbox (no real cards)</option><option value="production"' + (c.env === 'production' ? ' selected' : '') + '>Production</option></select>'
      + field('platform', 'Platform name', c.platform_name, 'e.g. KiddieTracAgency')
      + '<label style="' + Lb + '">API key</label><input id="tg-key" type="password" data-kt-nofit="1" autocomplete="off" placeholder="' + (c.key_mask ? 'Saved (' + esc(c.key_mask) + '). Paste a new key to replace it.' : 'Paste the API key') + '" style="' + F + 'margin:4px 0 10px;">'
      + field('customer', 'Customer identifier', c.customer_identifier, 'e.g. kiddietrac-customer')
      + field('account', 'Account identifier', c.account_identifier, 'e.g. kiddietrac-rewards')
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:6px;"><input type="checkbox" id="tg-en"' + (c.enabled ? ' checked' : '') + '> ' + 'Use Tango Card for educator rewards' + '</label>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:6px;"><span id="tg-msg" style="align-self:center;font-size:12.5px;"></span>'
      + (c.key_mask ? '<button type="button" id="tg-clear" class="kt-btn">Remove key</button><button type="button" id="tg-test" class="kt-btn">Test connection</button>' : '')
      + '<button type="button" id="tg-save" class="kt-btn kt-btn-primary">Save</button></div></div>';
    var msg = box.querySelector('#tg-msg');
    function save(p) {
      msg.style.color = '#64748B'; msg.textContent = 'Saving…';
      return Api.put(url, p).then(function () { toast('Tango Card settings saved', 'success'); drawTangoBox(main, box); })
        .catch(function (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Could not save'; });
    }
    box.querySelector('#tg-save').addEventListener('click', function () {
      var p = { env: box.querySelector('#tg-env').value, enabled: box.querySelector('#tg-en').checked,
        platform_name: box.querySelector('#tg-platform').value.trim(), customer_identifier: box.querySelector('#tg-customer').value.trim(),
        account_identifier: box.querySelector('#tg-account').value.trim() };
      var k = box.querySelector('#tg-key').value.trim(); if (k) { p.api_key = k; }
      save(p);
    });
    var cl = box.querySelector('#tg-clear');
    if (cl) { cl.addEventListener('click', function () { save({ clear_key: true }); }); }
    var tb = box.querySelector('#tg-test');
    if (tb) {
      tb.addEventListener('click', async function () {
        msg.style.color = '#64748B'; msg.textContent = 'Testing…';
        try { var r = await Api.post(url + '/test', {}); msg.style.color = r.ok ? '#065F46' : '#B91C1C'; msg.textContent = r.message; }
        catch (e) { msg.style.color = '#B91C1C'; msg.textContent = (e && e.message) || 'Test failed'; }
      });
    }
  }

  /* Settings → Payment providers → 🎁 Gift cards calls this (2026-11-02). Agency accounts only (no KiddieTrac
     account, no fallback): /educator-points/giftbit and /educator-points/tango, for the agency being viewed. */
  KT.educatorGiftCards = function (kind, main, body) {
    if (!isAgencyAdmin()) { return; }
    return drawGiftbit(main, body);
  };
  ['educator', 'home_visitor', 'centre_director'].forEach(function (r) { KT.Shell.registerScreen(r + ':my-points', renderMine); });
  ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (r) { KT.Shell.registerScreen(r + ':educator-rewards', renderRewards); });
  // Settings → Educator awards (2026-11-02): the automatic award settings. Directors read only.
  ['agency_admin', 'platform_admin', 'centre_director'].forEach(function (r) { KT.Shell.registerScreen(r + ':educator-award-settings', renderAwardSettings); });
})(window);
