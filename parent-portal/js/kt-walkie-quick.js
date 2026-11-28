/* ═══════════════════════════════════════════════════════════════════════
   📻 WALKIE-TALKIE QUICK ACTION (2026-11-28). Anthony: "add walkie-talkie to the top bar with a quick action for
   walkie-talkie functions".

   • The 📻 button: admins, directors and super admins in the top bar (kt-topbar #kt-tb-walkie, next to 📞);
     educators and home visitors in theirs (kt-parent-chrome #kt-pc-walkie); on a phone (the app and phone-width
     browsers) NO header button: a pinned first tile in the home Quick access grid instead (pinTile, 2026-11-28). Shown only when /walkie/me says the walkie is on for this
     person in this agency (staff of the agency; a super admin in his active agency).
   • On it: the number of talks sent to you that you have not played (WalkieController::unplayed, carried by
     /walkie/me and /walkie/inbox), a soft teal glow while someone is talking to you (red for the emergency channel)
     and a red ring while you talk.
   • It opens ONE quick popover (KT.quickPop, the house glass popover): a card with who you talk to (the last one you
     picked; ▾ opens Recent, Channels and People with a search) and the big 🎙 hold-to-talk button (mouse, finger, or
     hold Space/Enter on it), then ▶ Play latest, Listening / Do not disturb, Open the Walkie-talkie, My walkie
     settings.
   • Never talks or fetches on its own: everything goes through kt-walkie.js (KT.walkie.talkStart/talkEnd/playClip/
     load/refresh and its kt:walkie-me / kt:walkie-inbox / kt:walkie-live events). Its only own call is
     PUT /walkie/prefs for Do not disturb, the same call My settings makes.
   • The last person or channel and the recent ones are remembered per person in this browser (kt_wtq_<user id>).
   ═══════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT = w.KT || {};
  if (KT.walkieQuick) return;

  var Q = { me: null, unplayed: 0, latest: null, live: null, talking: false, shown: null, pop: null, card: null, playing: false };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function T(s, c) { try { return (KT.i18n && KT.i18n.t) ? KT.i18n.t(s, c) : s; } catch (e) { return s; } }
  function toast(i, t, m, c) { try { KT.toast && KT.toast(i, T(t), m ? T(m) : '', c); } catch (e) {} }
  function api() { return KT.Api; }
  function wk() { return KT.walkie || null; }
  function uid() { try { var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); return u && u.id ? String(u.id) : ''; } catch (e) { return ''; } }
  function enabled() { return !!(Q.me && Q.me.enabled && Q.me.me); }

  /* ── who to talk to: the last one picked, and the recent ones (this browser, per person) ───────────── */
  function memKey() { return 'kt_wtq_' + uid(); }
  function mem() { try { var j = JSON.parse(localStorage.getItem(memKey()) || 'null'); return j && typeof j === 'object' ? j : {}; } catch (e) { return {}; } }
  function remember(k) {
    var m = mem(); m.last = k; m.recent = [k].concat((m.recent || []).filter(function (x) { return x !== k; })).slice(0, 5);
    try { localStorage.setItem(memKey(), JSON.stringify(m)); } catch (e) {}
  }
  function all() {
    var out = {}; if (!Q.me) return out;
    (Q.me.channels || []).forEach(function (c) { out[c.key] = { key: c.key, label: c.label, icon: c.icon || '📻', can_talk: c.can_talk, emergency: c.emergency, talking: c.talking }; });
    (Q.me.people || []).forEach(function (p) { out[p.key] = { key: p.key, label: p.name, person: p, can_talk: true, talking: p.talking, sub: p.role }; });
    return out;
  }
  function target() {
    var a = all(), m = mem();
    // The last chat is kept on the server (per person and agency, /walkie/me "last"): every device opens on it.
    if (Q.me && Q.me.last && a[Q.me.last]) return a[Q.me.last];
    if (m.last && a[m.last]) return a[m.last];
    var first = (Q.me && Q.me.channels || []).filter(function (c) { return c.can_talk && !c.emergency; })[0] || (Q.me && Q.me.channels || [])[0];
    return first ? a[first.key] : null;
  }

  /* ── styles ──────────────────────────────────────────────────────────── */
  function css() {
    if (d.getElementById('kt-wtq-css')) return;
    var s = d.createElement('style'); s.id = 'kt-wtq-css';
    s.textContent = ''
      /* THE TALK BUTTON IS ORANGE (2026-11-28, Anthony: "the hold-to-talk mic button should be a bold ORANGE that stands
         out"). Same everywhere (this screen, the top-bar popover, the phone sheet, the reply card): bright #F97316 rim
         into a #C2410C middle so the white mic sits on 5.2:1 (white on #F97316 alone is 2.8:1); transmitting = deeper
         #9A3412 with a glowing orange ring; someone else talking = slate; the emergency channel keeps red. A functional
         colour: white-label tints never restyle it. */
      + '.kt-ptt-o{background:radial-gradient(circle at 50% 50%,#C2410C 0,#C2410C 44%,#EA580C 68%,#F97316 100%) !important;color:#fff !important;box-shadow:0 8px 22px rgba(194,65,12,.38),inset 0 2px 0 rgba(255,255,255,.25) !important;}'
      + '.kt-ptt-o:hover{filter:brightness(1.05);}'
      + '.kt-ptt-o.on{background:radial-gradient(circle at 50% 50%,#9A3412 0,#9A3412 50%,#C2410C 78%,#EA580C 100%) !important;box-shadow:0 0 0 8px rgba(249,115,22,.30),0 0 26px 8px rgba(249,115,22,.45) !important;animation:ktPttGlow 1.1s ease-in-out infinite;}'
      + '@keyframes ktPttGlow{50%{box-shadow:0 0 0 12px rgba(249,115,22,.18),0 0 32px 12px rgba(249,115,22,.30);}}'
      + '.kt-ptt-o.busy:not(.on){background:radial-gradient(circle at 50% 50%,#475569 0,#475569 50%,#64748B 100%) !important;box-shadow:0 0 0 3px rgba(100,116,139,.25) !important;}'
      + '.kt-ptt-o.em:not(.busy){background:radial-gradient(circle at 50% 50%,#991B1B 0,#991B1B 46%,#B91C1C 72%,#DC2626 100%) !important;box-shadow:0 8px 22px rgba(153,27,27,.4) !important;}'
      + '.kt-ptt-o.em.on{background:radial-gradient(circle at 50% 50%,#7F1D1D 0,#7F1D1D 50%,#991B1B 100%) !important;box-shadow:0 0 0 8px rgba(220,38,38,.28),0 0 26px 8px rgba(220,38,38,.4) !important;}'
      + '.kt-ptt-o[disabled]{opacity:.45;}.kt-ptt-o .wt-mic{display:block;margin:auto;filter:drop-shadow(0 1px 1px rgba(0,0,0,.25));}'
      + '@media (prefers-reduced-motion:reduce){.kt-ptt-o.on{animation:none;}}'
      // the pinned Quick access tile (phones): the unplayed count and a 📌 in Customize
      + '.kt-wtq-tile{position:relative;}'
      + '.kt-wtq-tile .wtq-badge{position:absolute;top:2px;right:calc(50% - 30px);min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#DC2626;color:#fff;font:800 11px/18px system-ui,sans-serif;box-sizing:border-box;text-align:center;border:2px solid #fff;}'
      + '.kt-wtq-tile .wtq-badge[hidden]{display:none;}'
      + '.kt-wtq-tile .wtq-pin{display:none;position:absolute;top:2px;left:4px;font-size:14px;}.kt-wtq-tile.wtq-editing .wtq-pin{display:block;}'
      + '.kt-wtq-tile.wtq-editing .kt-tile-icon{animation:none !important;}'
      + '.kt-wtq-tile.wtq-glow,.kt-wtq-tile.wtq-me{animation:none;box-shadow:none !important;}'
      + '.kt-wtq-tile.wtq-glow .kt-tile-icon{animation:wtqGlow 1.4s ease-in-out infinite;}.kt-wtq-tile.wtq-glow.wtq-em .kt-tile-icon{animation-name:wtqGlowEm;}'
      + '.kt-wtq-tile.wtq-me .kt-tile-icon{box-shadow:0 0 0 3px #DC2626 !important;}'
      + '.kt-wtq-tile.wtq-dnd{opacity:1;}.kt-wtq-tile.wtq-dnd .kt-tile-icon{opacity:.6;}'
      // someone is talking to you: a soft teal pulse (red for the emergency channel); you are talking: a red ring
      + '.wtq-glow{animation:wtqGlow 1.4s ease-in-out infinite;}'
      + '.wtq-glow.wtq-em{animation-name:wtqGlowEm;}'
      + '@keyframes wtqGlow{0%,100%{box-shadow:0 0 0 0 rgba(21,159,180,.55);}50%{box-shadow:0 0 0 6px rgba(21,159,180,0);}}'
      + '@keyframes wtqGlowEm{0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,.6);}50%{box-shadow:0 0 0 6px rgba(220,38,38,0);}}'
      + '.wtq-me{box-shadow:0 0 0 2px #DC2626 !important;}'
      + '.wtq-dnd{opacity:.55;}'
      + '@media (prefers-reduced-motion: reduce){.wtq-glow{animation:none;box-shadow:0 0 0 2px #159FB4;}.wtq-glow.wtq-em{box-shadow:0 0 0 2px #DC2626;}}'
      /* THE POPOVER, SOLID (2026-11-28, Anthony: "the walkie talkie popup on the top bar is hard to read with the popup -
         full sweep to make this better"). The house quick popover is see-through glass bubbles over a light dim; with a
         talk button, a picker and four choices the page bled through every one of them. This one is ONE solid panel:
         white (dark navy only when the portal is dark), a real border and shadow, a darker dim behind it, 13px+ text in
         slate-900/600 (AA on white), sections split by rules. Phones: a full-width sheet from the bottom. */
      + 'body.wtq-open .kt-qp-dim{background:rgba(15,23,42,.38);-webkit-backdrop-filter:none;backdrop-filter:none;}'
      + '#kt-wtq-pop{grid-template-columns:minmax(0,1fr);overflow-x:hidden;}#kt-wtq-pop .wtq,#kt-wtq-pop .wtq-row,#kt-wtq-pop .wtq-to{min-width:0;}'
      + '#kt-wtq-pop{gap:0;padding:6px;background:#FFFFFF;color:#0F172A;border:1px solid #CBD5E1;border-radius:16px;box-shadow:0 22px 50px -12px rgba(15,23,42,.5),0 2px 6px rgba(15,23,42,.12);scrollbar-width:thin;}'
      + '#kt-wtq-pop .kt-qp-card,#kt-wtq-pop .kt-qp-item{background:transparent;color:#0F172A;border:0;border-radius:10px;box-shadow:none;-webkit-backdrop-filter:none;backdrop-filter:none;}'
      + '#kt-wtq-pop .kt-qp-card{padding:2px 4px 4px;}'
      + '#kt-wtq-pop .kt-qp-item{min-height:50px;padding:8px 10px;gap:12px;}'
      + '#kt-wtq-pop .kt-qp-item:hover,#kt-wtq-pop .kt-qp-item:focus-visible{background:#F1F5F9;}'
      + '#kt-wtq-pop .kt-qp-item:focus-visible{outline:2px solid #0E7490;outline-offset:-2px;}'
      + '#kt-wtq-pop .kt-qp-item[disabled]{opacity:1;color:#64748B;}'
      + '#kt-wtq-pop .kt-qp-i{font-size:20px;width:26px;}'
      + '#kt-wtq-pop .kt-qp-t{font-size:14.5px;font-weight:800;color:inherit;}'
      + '#kt-wtq-pop .kt-qp-s{font-size:13px;font-weight:600;opacity:1;color:#475569;margin-top:2px;}'
      + '@media (max-width:600px){#kt-wtq-pop{left:0 !important;right:0 !important;top:auto !important;bottom:0 !important;width:auto !important;max-width:none !important;max-height:88vh !important;max-height:88dvh !important;'
      +   'border-radius:18px 18px 0 0;border-bottom:0;padding:8px 8px calc(env(safe-area-inset-bottom,0px) + 10px);transform-origin:50% 100% !important;}}'
      // the card: header with icon buttons, talk button beside who, search + short list, two action chips (ui-tight-and-clean)
      + '.wtq{display:flex;flex-direction:column;gap:8px;font-size:14px;color:#0F172A;}'
      + '.wtq-hd{display:flex;align-items:center;gap:4px;}'
      + '.wtq-title{flex:1;min-width:0;display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#0E7490;}'
      + 'button.wtq-ib{width:30px;height:30px;min-width:0 !important;min-height:0 !important;padding:0;border:1px solid transparent;border-radius:8px;background:transparent;color:#334155;font-size:16px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}'
      + 'button.wtq-ib:hover{background:#F1F5F9;border-color:#E2E8F0;}button.wtq-ib:focus-visible{outline:2px solid #0E7490;outline-offset:1px;}button.wtq-ib.wtq-x{font-size:15px;font-weight:800;}'
      + '.wtq-live{display:flex;align-items:center;gap:8px;padding:5px 10px;border-radius:10px;background:#ECFEFF;border:1px solid #A5F3FC;font-size:13.5px;font-weight:800;color:#155E75;}'
      + '.wtq-live[hidden]{display:none;}.wtq-live i{width:9px;height:9px;border-radius:50%;background:#0E7490;animation:wtqDot 1s ease-in-out infinite;flex:0 0 auto;}'
      + '.wtq-live.em{background:#FEF2F2;border-color:#FECACA;color:#991B1B;}.wtq-live.em i{background:#DC2626;}'
      + '@keyframes wtqDot{50%{opacity:.25;}}'
      + '.wtq-talk{display:flex;align-items:center;gap:12px;}'
      + '.wtq-who{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px;}'
      + '.wtq-to-l{font-size:13px;font-weight:700;color:#475569;}'
      + '.wtq-to-n{font-weight:800;font-size:15px;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      + '.wtq-st{font-size:13px;font-weight:600;color:#334155;min-height:17px;}'
      + 'button.wtq-btn{flex:0 0 auto;width:84px;height:84px;min-height:0 !important;border-radius:50%;border:0;background:radial-gradient(circle at 35% 30%,#1BA3B8,#0B4F68);color:#fff;font-size:38px;line-height:1;box-shadow:0 6px 16px rgba(14,90,117,.35);cursor:pointer;touch-action:none;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;transition:transform .08s;}'
      + 'button.wtq-btn:focus-visible{outline:3px solid #0E7490;outline-offset:3px;}'
      + 'button.wtq-btn.on{transform:scale(.96);background:radial-gradient(circle at 35% 30%,#F87171,#B91C1C);box-shadow:0 0 0 8px rgba(220,38,38,.15),0 6px 16px rgba(185,28,28,.35);}'
      + 'button.wtq-btn[disabled]{opacity:.45;cursor:not-allowed;}'
      + '.wtq-q{width:100%;height:34px;box-sizing:border-box;border:1px solid #94A3B8;border-radius:8px;padding:0 10px;font:inherit;font-size:14px;background:#FFFFFF;color:#0F172A;}'
      + '.wtq-q::placeholder{color:#64748B;}.wtq-q:focus{outline:2px solid #0E7490;outline-offset:0;border-color:#0E7490;}'
      + '.wtq-list{max-height:208px;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;border:1px solid #E2E8F0;border-radius:10px;padding:0 4px 4px;margin-top:-2px;}'
      + 'button.wtq-hb{display:flex;align-items:center;gap:6px;width:100%;min-height:0 !important;border:0;margin:0;cursor:pointer;text-align:left;font-family:inherit;}button.wtq-hb:focus-visible{outline:2px solid #0E7490;outline-offset:-2px;}'
      + '.wtq-chev{display:inline-block;transition:transform .15s;}.wtq-chev.col{transform:rotate(-90deg);}'
      + '.wtq-hc{margin-left:auto;font-size:12px;font-weight:700;color:#475569;letter-spacing:0;}'
      + '.wtq-hn{margin-left:auto;padding:1px 7px;border-radius:999px;background:#DC2626;color:#fff;font-size:12px;font-weight:800;letter-spacing:0;text-transform:none;}'
      + '.wtq-h{position:sticky;top:0;z-index:1;background:#FFFFFF;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#0E7490;padding:6px 6px 2px;}'
      + 'button.wtq-row{display:flex;align-items:center;gap:9px;width:100%;min-height:32px !important;height:auto;padding:3px 8px;border:0;border-radius:8px;background:transparent;color:#0F172A;font:inherit;font-size:14px;text-align:left;cursor:pointer;}'
      + 'button.wtq-row:hover,button.wtq-row:focus-visible{background:#F1F5F9;outline:none;}button.wtq-row.on{background:#E0F2FE;box-shadow:inset 3px 0 0 #0E7490;}'
      + 'button.wtq-row[disabled]{color:#64748B;cursor:default;}'
      + '.wtq-ri{position:relative;width:24px;text-align:center;flex:0 0 24px;font-size:16px;display:inline-flex;justify-content:center;}'
      + '.wtq-dot{position:absolute;right:-2px;bottom:-1px;width:9px;height:9px;border-radius:50%;background:#16A34A;border:2px solid #fff;}.wtq-dot.ph{background:#0891B2;}'
      + '.wtq-rn{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;}'
      + '.wtq-rs{font-size:13px;color:#475569;white-space:nowrap;}'
      + '.wtq-none{font-size:13.5px;color:#475569;padding:8px 6px;}'
      + '.wtq-acts{display:grid;grid-template-columns:1fr 1fr;gap:6px;}'
      + 'button.wtq-chip{display:flex;align-items:center;gap:8px;min-width:0;min-height:44px !important;padding:5px 9px;border:1px solid #CBD5E1;border-radius:10px;background:#F8FAFC;color:#0F172A;font:inherit;text-align:left;cursor:pointer;}'
      + 'button.wtq-chip:hover{background:#F1F5F9;border-color:#94A3B8;}button.wtq-chip:focus-visible{outline:2px solid #0E7490;outline-offset:1px;}'
      + 'button.wtq-chip[disabled]{cursor:default;color:#475569;}button.wtq-chip[disabled]:hover{background:#F8FAFC;border-color:#CBD5E1;}'
      + '.wtq-ci{font-size:17px;flex:0 0 auto;}.wtq-ct{min-width:0;display:flex;flex-direction:column;line-height:1.2;}'
      + '.wtq-ct b{font-size:13.5px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
      /* phones (the sheet): the talk button big and central, who above it (2026-11-28, Anthony: "the mic button needs to be bigger") */
      + '@media (max-width:600px){.wtq-talk{flex-direction:column;gap:6px;}.wtq-who{align-items:center;text-align:center;order:-1;width:100%;}'
      +   'button.wtq-btn{width:clamp(150px,44vw,172px);height:clamp(150px,44vw,172px);font-size:clamp(62px,18vw,74px);}.wtq-list{max-height:min(168px,24vh);}}'
      + '.wtq-cs{font-size:13px;font-weight:600;color:#475569;line-height:1.2;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}'
      // dark: only when the PORTAL is dark (as the right panel and Quick notes), never the OS alone
      + 'html.kt-dark #kt-wtq-pop,html[data-theme="dark"] #kt-wtq-pop{background:#0F172A;color:#F1F5F9;border-color:#334155;}'
      + 'html.kt-dark #kt-wtq-pop .kt-qp-card,html.kt-dark #kt-wtq-pop .kt-qp-item,html[data-theme="dark"] #kt-wtq-pop .kt-qp-card,html[data-theme="dark"] #kt-wtq-pop .kt-qp-item{color:#F1F5F9;border-color:#1E293B;}'
      + 'html.kt-dark #kt-wtq-pop .kt-qp-item:hover,html[data-theme="dark"] #kt-wtq-pop .kt-qp-item:hover{background:#1E293B;}'
      + 'html.kt-dark #kt-wtq-pop .kt-qp-s,html[data-theme="dark"] #kt-wtq-pop .kt-qp-s,html.kt-dark .wtq-to-l,html[data-theme="dark"] .wtq-to-l,html.kt-dark .wtq-rs,html[data-theme="dark"] .wtq-rs,html.kt-dark .wtq-st,html[data-theme="dark"] .wtq-st{color:#CBD5E1;}'
      + 'html.kt-dark .wtq,html[data-theme="dark"] .wtq,html.kt-dark .wtq-to-n,html[data-theme="dark"] .wtq-to-n,html.kt-dark .wtq-row,html[data-theme="dark"] .wtq-row{color:#F1F5F9;}'
      + 'html.kt-dark .wtq-to,html[data-theme="dark"] .wtq-to,html.kt-dark .wtq-q,html[data-theme="dark"] .wtq-q{background:#1E293B;border-color:#475569;color:#F1F5F9;}'
      + 'html.kt-dark .wtq-list,html[data-theme="dark"] .wtq-list{border-color:#334155;}'
      + 'html.kt-dark .wtq-row:hover,html[data-theme="dark"] .wtq-row:hover{background:#1E293B;}html.kt-dark .wtq-row.on,html[data-theme="dark"] .wtq-row.on{background:#164E63;}'
      + 'html.kt-dark .wtq-title,html[data-theme="dark"] .wtq-title,html.kt-dark .wtq-h,html[data-theme="dark"] .wtq-h,html.kt-dark .wtq-chg,html[data-theme="dark"] .wtq-chg{color:#5EEAD4;}'
      + 'html.kt-dark .wtq-live,html[data-theme="dark"] .wtq-live{background:#083344;border-color:#155E75;color:#A5F3FC;}'
      + 'html.kt-dark .wtq-h,html[data-theme="dark"] .wtq-h{background:#0F172A;}html.kt-dark .wtq-dot,html[data-theme="dark"] .wtq-dot{border-color:#0F172A;}'
      + 'html.kt-dark button.wtq-ib,html[data-theme="dark"] button.wtq-ib{color:#E2E8F0;}html.kt-dark button.wtq-ib:hover,html[data-theme="dark"] button.wtq-ib:hover{background:#1E293B;border-color:#334155;}'
      + 'html.kt-dark button.wtq-chip,html[data-theme="dark"] button.wtq-chip{background:#1E293B;border-color:#475569;color:#F1F5F9;}'
      + 'html.kt-dark .wtq-cs,html[data-theme="dark"] .wtq-cs,html.kt-dark button.wtq-chip[disabled],html[data-theme="dark"] button.wtq-chip[disabled]{color:#CBD5E1;}';
    d.head.appendChild(s);
  }

  /* ── the buttons ─────────────────────────────────────────────────────── */
  function buttons() { return ['kt-tb-walkie', 'kt-pc-walkie'].map(function (id) { return d.getElementById(id); }).filter(Boolean).concat([].slice.call(d.querySelectorAll('.kt-wtq-tile'))); }
  function badgeOf(b) { return b.querySelector('#kt-tb-walkie-badge,#kt-pc-walkie-badge,.wtq-badge'); }
  /* PHONES: THE PINNED "Walkie-talkie" TILE (2026-11-28, Anthony: "in mobile app remove the walkie-talkie at the top bar
     and place it in quick access as the first option (static and cannot be changed)"). No 📻 in the phone header any
     more: the home screen's Quick access grid gets a Walkie-talkie tile in FIRST place, for every role with the walkie
     (admins and directors: screen-admin-home.js; educators and home visitors: screen-role-home.js call pinTile).
     It is not part of the saved tile order (so never duplicated, never counted against the 12), and in ✏️ Customize it
     shows 📌 with no ✕ and no ◀ ▶. A tap opens the same quick popover, as a sheet from the bottom. */
  function phone() { var mn = d.getElementById('kt-mobilenav'); return !!(mn && w.getComputedStyle(mn).display !== 'none'); }
  function pinTile(grid, opts) {
    if (!grid) return;
    css();
    grid.setAttribute('data-wtq-pin', '1');   // found again on every repaint, even if this file loaded after the home screen
    var t = grid.querySelector('.kt-wtq-tile');
    var want = enabled() && phone();
    if (!want) { if (t) t.remove(); return; }
    if (!t) {
      t = d.createElement('a');
      t.className = 'kt-tile kt-wtq-tile'; t.href = '#walkie'; t.setAttribute('role', 'button'); t.setAttribute('data-kt-pinned', '1'); t.setAttribute('aria-haspopup', 'menu');
      t.innerHTML = '<span class="kt-tile-icon" aria-hidden="true">📻</span><span class="kt-tile-label">' + esc(T('Walkie-talkie')) + '</span>'
        + '<span class="wtq-badge" hidden></span><span class="wtq-pin" aria-hidden="true" data-kttip="' + esc(T('Always first')) + '">📌</span>';
      t.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); open(t); });
      grid.insertBefore(t, grid.firstChild);
    } else if (grid.firstChild !== t) grid.insertBefore(t, grid.firstChild);
    var editing = !!(opts && opts.editing) || !!(grid.closest && grid.closest('.kt-qa-editing'));
    t.classList.toggle('wtq-editing', editing);
    paintOne(t);
  }
  function syncTiles() {
    [].forEach.call(d.querySelectorAll('[data-wtq-pin]'), function (g) { pinTile(g); });
    var f = d.getElementById('kt-wtq-fab'); if (f) f.remove();   // the old phone-header button (2026-11-28 morning)
  }
  function paintOne(b) {
    var on = enabled();
    var disp = on && !(b.id && phone()) ? '' : 'none';   // phones: the header buttons give way to the pinned tile
    if (b.id && b.style.display !== disp) { b.style.display = disp; if (b.id === 'kt-tb-walkie' && KT.topbarFit) KT.topbarFit(); }
    var n = Q.unplayed, bd = badgeOf(b);
    if (bd) { var txt = n > 99 ? '99+' : String(n); if (n > 0) { if (bd.textContent !== txt) bd.textContent = txt; bd.hidden = false; } else if (!bd.hidden) bd.hidden = true; }
    var dnd = !!(Q.me && Q.me.prefs && Q.me.prefs.dnd);
    b.classList.toggle('wtq-glow', !!Q.live && !Q.talking);
    b.classList.toggle('wtq-em', !!(Q.live && Q.live.emergency));
    b.classList.toggle('wtq-me', Q.talking);
    b.classList.toggle('wtq-dnd', dnd && !Q.live && !Q.talking);
    var label = T('Walkie-talkie') + (Q.live ? ' · ' + Q.live.from + ' ' + T('is talking') : '') + (n > 0 ? ' · ' + n + ' ' + T('new', 'walkie') : '') + (dnd ? ' · ' + T('Do not disturb is on') : '');
    if (b.getAttribute('aria-label') !== label) { b.setAttribute('aria-label', label); b.setAttribute('data-kttip', label); }
  }
  function paint() {
    css();
    syncTiles();
    buttons().forEach(paintOne);
    paintCard();
  }

  /* ── the quick popover ───────────────────────────────────────────────── */
  function open(anchor) {
    if (Q.pop) { Q.pop.close(); return; }
    var W = wk();
    if (!W || !KT.quickPop) { location.hash = '#walkie'; return; }
    if (!enabled()) {
      // Not loaded yet (or the agency changed): ask once, then open.
      if (W.load) W.load().then(function (r) { Q.me = r; paint(); if (enabled()) open(anchor); else location.hash = '#walkie'; }, function () { location.hash = '#walkie'; });
      return;
    }
    css();
    var card = buildCard();
    var items = [{ el: card }];
    Q.pop = KT.quickPop({ anchor: anchor, id: 'kt-wtq-pop', label: T('Walkie-talkie'), width: 340, items: items, onClose: function () {
      d.body.classList.remove('wtq-open');
      if (Q.card && Q.card.ptt && Q.card.ptt.held()) Q.card.ptt.release(); else if (Q.talking && W.talkEnd) W.talkEnd();   // letting go of the popover lets go of the button
      Q.pop = null; Q.card = null;
    } });
    d.body.classList.add('wtq-open');
    paintCard();   // the card is in the page now: who you talk to, and the list
    // A computer: straight into the search box (type a name, Enter picks). A phone: no keyboard popping up over the sheet.
    setTimeout(function () { var C = Q.card; if (!C) return; var f = phone() ? null : C.q; if (f) try { f.focus({ preventScroll: true }); } catch (e) {} }, 140);
  }
  function setItem(id, icon, label, sub, disabled) {
    var b = d.getElementById(id); if (!b) return;
    var i = b.querySelector('.wtq-ci'), t = b.querySelector('.wtq-ct');
    if (i && i.textContent !== icon) i.textContent = icon;
    var h = '<b>' + esc(label) + '</b>' + (sub ? '<span class="wtq-cs">' + esc(sub) + '</span>' : '');
    if (t && t.innerHTML !== h) t.innerHTML = h;
    b.disabled = !!disabled;
  }
  function ago(s) {
    s = +s || 0;
    if (s < 45) return T('just now');
    if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' ' + T('min ago');
    if (s < 86400) return Math.round(s / 3600) + ' ' + T('h ago');
    return Math.round(s / 86400) + ' ' + T('d ago');
  }
  function playSub() {
    if (Q.playing) return T('Playing…');
    var L = Q.latest; if (!L) return T('Nothing new');
    return (Q.unplayed > 1 ? Q.unplayed + ' ' + T('new', 'walkie') + ' · ' : '') + (L.emergency ? '🚨 ' : '') + L.from + (L.label && L.label !== 'Direct' ? ' · ' + L.label : '') + ' · ' + ago(L.ago_s);
  }
  function playLatest() {
    var W = wk(), L = Q.latest; if (!W || !W.playClip || !L || Q.playing) return;
    Q.playing = true; setItem('wtq-play', '⏳', T('Play latest'), playSub(), true);
    W.playClip(L.id).then(function (secs) {
      // The server marked it heard on fetching it: count it off now, then ask for the real numbers once it has played.
      if (Q.latest && Q.latest.id === L.id) { Q.unplayed = Math.max(0, Q.unplayed - 1); Q.latest = null; }
      setTimeout(function () { Q.playing = false; paint(); if (W.refresh) W.refresh(); }, Math.round((+secs || 0) * 1000) + 300);
      paint();
    }, function () { Q.playing = false; paint(); });
  }
  function dndOn() { return !!(Q.me && Q.me.prefs && Q.me.prefs.dnd); }
  function dndIcon() { return dndOn() ? '🔕' : '🔊'; }
  function dndLabel() { return dndOn() ? T('Do not disturb is on') : T('Listening'); }
  function dndSub() { return dndOn() ? T('Tap to listen again') : T('Tap for Do not disturb (1 hour)'); }
  function toggleDnd() {
    var on = !dndOn();
    setItem('wtq-dnd', '⏳', dndLabel(), dndSub(), true);
    api().request('/walkie/prefs', { method: 'PUT', body: on ? { dnd: true, minutes: 60 } : { dnd: false, minutes: 0 } }).then(function (x) {
      if (Q.me) Q.me.prefs = Object.assign({}, Q.me.prefs || {}, x || { dnd: on });
      var m = wk() && wk().me && wk().me(); if (m && m !== Q.me) m.prefs = Object.assign({}, m.prefs || {}, x || { dnd: on });
      if (KT.savedToast) KT.savedToast(); paint();
    }, function (e) { paint(); toast('⚠️', 'Could not save', (e && e.data && e.data.message) || '', '#DC2626'); });
  }

  /* The card (2026-11-28, Anthony: "should have a quick search and the drop down not change to select user quickly and the
     settings should be an icon to reduce the size ... including the open the walkie-talkie should be a maximize icon").
     Header: 📻 title, ⚙️ my walkie settings, ⤢ the full Walkie-talkie, ✕. Then the talk button beside who you talk to,
     a search box that is always there (type-ahead, focused on a computer) over a short list (about 6 rows: the last
     chat and recent ones, then channels, then people on shift / listening first), and ▶ Play latest + Listening.
     One tap on a row picks it; it stays picked through every repaint until another is picked (and is the last chat). */
  function buildCard() {
    var W = wk();
    var el = d.createElement('div'); el.className = 'wtq'; el.setAttribute('data-i18n-ctx', 'walkie'); el.setAttribute('data-kt-iconized', '1');
    el.innerHTML = '<div class="wtq-hd"><div class="wtq-title"><span aria-hidden="true">📻</span>' + esc(T('Walkie-talkie')) + '</div>'
      + '<button type="button" class="wtq-ib" data-wtq="set" data-kttip="' + esc(T('Walkie settings')) + '" aria-label="' + esc(T('Walkie settings')) + '" data-kt-iconized="1">⚙️</button>'
      + '<button type="button" class="wtq-ib" data-wtq="max" data-kttip="' + esc(T('Open full walkie-talkie')) + '" aria-label="' + esc(T('Open full walkie-talkie')) + '" data-kt-iconized="1">⤢</button>'
      + '<button type="button" class="wtq-ib wtq-x" data-wtq="x" data-kttip="' + esc(T('Close')) + '" aria-label="' + esc(T('Close')) + '" data-kt-iconized="1">✕</button></div>'
      + '<div class="wtq-live" hidden><i></i><span data-no-i18n></span></div>'
      + '<div class="wtq-talk"><button type="button" class="wtq-btn kt-ptt-o" data-kt-iconized="1" aria-label="' + esc(T('Hold to talk')) + '"><svg class="wt-mic" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="8.6" y="2.2" width="6.8" height="12.2" rx="3.4" fill="#fff" stroke="none"/><path d="M5 11a7 7 0 0 0 14 0M12 18.2v3.3M8.4 21.5h7.2"/></svg></button>'
      + '<div class="wtq-who"><div class="wtq-to-l">' + esc(T('Talking to:')) + '</div><div class="wtq-to-n" data-no-i18n></div><div class="wtq-st" aria-live="polite"></div></div></div>'
      + '<input type="search" class="wtq-q" autocomplete="off" placeholder="🔍 ' + esc(T('Search channels and people')) + '" aria-label="' + esc(T('Search channels and people')) + '">'
      + '<div class="wtq-list" role="listbox" aria-label="' + esc(T('Pick who to talk to')) + '"></div>'
      + '<div class="wtq-acts"><button type="button" class="wtq-chip" id="wtq-play" data-kt-iconized="1"><span class="wtq-ci">▶️</span><span class="wtq-ct"></span></button>'
      + '<button type="button" class="wtq-chip" id="wtq-dnd" data-kt-iconized="1"><span class="wtq-ci"></span><span class="wtq-ct"></span></button></div>';
    var C = Q.card = { el: el, name: el.querySelector('.wtq-to-n'), q: el.querySelector('.wtq-q'), list: el.querySelector('.wtq-list'),
      btn: el.querySelector('.wtq-btn'), st: el.querySelector('.wtq-st'), live: el.querySelector('.wtq-live'), sel: target() };
    function go(h) { if (Q.pop) Q.pop.close(); location.hash = h; }
    el.querySelector('[data-wtq="set"]').addEventListener('click', function () { go((W && W.myLink) || '#settings?tab=notifications&sub=walkie'); });
    el.querySelector('[data-wtq="max"]').addEventListener('click', function () { go('#walkie'); });
    el.querySelector('[data-wtq="x"]').addEventListener('click', function () { if (Q.pop) Q.pop.close(); });
    el.querySelector('#wtq-play').addEventListener('click', playLatest);
    el.querySelector('#wtq-dnd').addEventListener('click', toggleDnd);
    C.q.addEventListener('input', function () { C.list.scrollTop = 0; drawList(); });
    C.q.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { var r = C.list.querySelector('.wtq-row:not([disabled])'); if (r) { e.preventDefault(); e.stopPropagation(); r.focus(); } }
      if (e.key === 'Enter') { var r1 = C.list.querySelector('.wtq-row:not([disabled])'); if (r1) { e.preventDefault(); r1.click(); } }
    });
    C.list.addEventListener('click', function (e) {
      var fb = e.target.closest('[data-wtq-fold]');
      if (fb) {
        if (C.q.value) return;   // searching: everything shows, nothing to fold
        var fk = fb.getAttribute('data-wtq-fold'), m = mem(); m.fold = m.fold || {}; m.fold[fk] = !m.fold[fk];
        try { localStorage.setItem(memKey(), JSON.stringify(m)); } catch (x) {}
        drawList(); var hb = C.list.querySelector('[data-wtq-fold="' + fk + '"]'); if (hb) try { hb.focus({ preventScroll: true }); } catch (x) {}
        return;
      }
      var r = e.target.closest('.wtq-row'); if (!r || r.disabled) return;
      var a = all()[r.getAttribute('data-k')]; if (!a) return;
      C.sel = a; remember(a.key); if (wk() && wk().remember) wk().remember(a.key);
      if (C.q.value) C.q.value = '';
      paintCard(); drawList(); try { C.btn.focus({ preventScroll: true }); } catch (x) {}
    });
    C.list.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      var rows = [].slice.call(C.list.querySelectorAll('.wtq-row:not([disabled])')), i = rows.indexOf(d.activeElement);
      if (i < 0) return; e.preventDefault(); e.stopPropagation();
      var n = e.key === 'ArrowDown' ? i + 1 : i - 1; if (n < 0) { C.q.focus(); return; } if (rows[n]) rows[n].focus();
    });
    // Hold to talk: pointer (mouse / finger) or Space / Enter held on the button.
    var ui = { state: function (s, x) {
      Q.talking = s === 'live' || s === 'opening';
      C.btn.classList.toggle('on', Q.talking);
      clearInterval(ui._iv);
      if (s === 'opening') C.st.textContent = T('Starting…');
      else if (s === 'live') {
        var t0 = Date.now(), who = x && x.recipients !== undefined ? ' · ' + x.recipients + ' ' + T('hearing') : '';
        C.st.textContent = T('Talking…') + who;
        ui._iv = setInterval(function () { if (!Q.talking) return clearInterval(ui._iv); C.st.textContent = T('Talking…') + ' ' + Math.round((Date.now() - t0) / 1000) + 's' + who; }, 500);
      } else C.st.textContent = x && x.duration_ms ? T('Sent') + ' · ' + (Math.round(x.duration_ms / 100) / 10) + 's' : T('Hold to talk');
      buttons().forEach(paintOne);
    } };
    // The one hold-to-talk handler (kt-walkie.js bindPtt): warm mic, release caught anywhere, never stuck.
    var W0 = wk(); if (W0 && W0.bindPtt) C.ptt = W0.bindPtt(C.btn, function () { if (!C.sel || Q.talking) return null; remember(C.sel.key); return C.sel.key; }, ui);
    paintCard(); drawList();
    return el;
  }
  function rowHtml(a, on) {
    var ico = a.person ? (KT.avatar ? KT.avatar(a.person.name, { size: 22, photoUrl: a.person.photo_url, sex: a.person.sex, userId: a.person.id }) : '👤') : esc(a.icon || '📻');
    var p = a.person, dot = '';
    if (p && p.on_shift) dot = '<i class="wtq-dot" title="' + esc(T('On shift')) + '"></i>';
    else if (p && p.listening) dot = '<i class="wtq-dot ph" title="' + esc(T('Phone listening')) + '"></i>';
    var sub = a.talking ? a.talking + ' ' + T('is talking') : (!a.can_talk ? T('Listen only') : (p && p.dnd ? '🔕' : (a.sub ? T(a.sub) : '')));
    return '<button type="button" class="wtq-row' + (on ? ' on' : '') + '" role="option" aria-selected="' + (on ? 'true' : 'false') + '" data-k="' + esc(a.key) + '"' + (a.can_talk ? '' : ' disabled') + ' data-kt-iconized="1">'
      + '<span class="wtq-ri">' + ico + dot + '</span><span class="wtq-rn" data-no-i18n>' + esc(a.label) + '</span>' + (sub ? '<span class="wtq-rs">' + esc(sub) + '</span>' : '') + '</button>';
  }
  function drawList() {
    var C = Q.card; if (!C || !Q.me) return;
    var a = all(), q = (C.q.value || '').trim().toLowerCase(), sel = C.sel && C.sel.key, h = '', seen = {};
    var match = function (x) { return !q || String(x.label || '').toLowerCase().indexOf(q) >= 0; };
    var row = function (x) { seen[x.key] = 1; return rowHtml(x, x.key === sel); };
    // the last chat and the recent ones first (the server's last chat, then this browser's recent picks)
    var rec = [Q.me.last, sel].concat(mem().recent || []).filter(function (k, i, arr) { return k && a[k] && a[k].can_talk && !a[k].emergency && arr.indexOf(k) === i && match(a[k]); }).slice(0, 4);
    /* Recent folds (2026-11-28, Anthony: "make the recent list collapsible"): ▾ + the unplayed count, kept per person. */
    var fold = mem().fold || {};
    function hdr(k, label, extra) {
      var col = !q && !!fold[k];
      return { col: col, html: '<button type="button" class="wtq-h wtq-hb" data-wtq-fold="' + k + '" aria-expanded="' + (col ? 'false' : 'true') + '" data-kt-iconized="1"><span class="wtq-chev' + (col ? ' col' : '') + '" aria-hidden="true">▾</span>' + esc(T(label)) + (extra || '') + '</button>' };
    }
    if (rec.length) { var hr = hdr('rec', 'Recent', Q.unplayed > 0 ? '<span class="wtq-hn">' + (Q.unplayed > 99 ? '99+' : Q.unplayed) + ' ' + esc(T('new', 'walkie')) + '</span>' : ''); h += hr.html + (hr.col ? '' : rec.map(function (k) { return row(a[k]); }).join('')); }
    var chs = (Q.me.channels || []).map(function (c) { return a[c.key]; }).filter(function (x) { return x && !seen[x.key] && match(x); });
    if (chs.length) { var hc = hdr('ch', 'Channels', '<span class="wtq-hc">' + chs.length + '</span>'); h += hc.html + (hc.col ? '' : chs.map(row).join('')); }
    var ppl = (Q.me.people || []).map(function (p) { return a[p.key]; }).filter(function (x) { return x && !seen[x.key] && match(x); });
    ppl.sort(function (x, y) { var ox = x.person.on_shift || x.person.listening ? 0 : 1, oy = y.person.on_shift || y.person.listening ? 0 : 1; return ox - oy || String(x.label).localeCompare(String(y.label)); });
    if (ppl.length) { var hp = hdr('ppl', 'People', '<span class="wtq-hc">' + ppl.length + '</span>'); h += hp.html + (hp.col ? '' : ppl.slice(0, q ? 60 : 40).map(row).join('')); }
    if (!h) h = '<div class="wtq-none">' + esc(T('No match.')) + '</div>';
    if (C.list._h === h) return;   // nothing changed: leave the list (and where it is scrolled) alone
    var top = C.list.scrollTop; C.list._h = h; C.list.innerHTML = h; C.list.scrollTop = top;
  }
  function paintCard() {
    var C = Q.card; if (!C || !C.el.isConnected) return;
    // The pick stays put through repaints; only someone gone out of reach falls back (to the last chat or default channel).
    if (C.sel && !all()[C.sel.key]) C.sel = target();
    var a = C.sel ? all()[C.sel.key] || C.sel : null;
    C.name.textContent = a ? ((a.person ? '👤 ' : (a.icon ? a.icon + ' ' : '📻 ')) + a.label) : T('Pick who to talk to');
    C.btn.disabled = !a || !a.can_talk;
    C.btn.classList.toggle('busy', !!(a && a.talking)); C.btn.classList.toggle('em', !!(a && a.emergency));
    if (!Q.talking) C.st.textContent = !a ? T('Pick who to talk to') : (!a.can_talk ? T('Listen only') : (a.talking ? a.talking + ' ' + T('is talking') : T('Hold to talk')));
    if (Q.live) { C.live.hidden = false; C.live.classList.toggle('em', !!Q.live.emergency); C.live.querySelector('span').textContent = (Q.live.emergency ? '🚨 ' : '') + Q.live.from + ' ' + T('is talking') + (Q.live.label && Q.live.label !== 'Direct' ? ' · ' + Q.live.label : ''); }
    else C.live.hidden = true;
    if (!Q.playing) setItem('wtq-play', '▶️', T('Play latest'), playSub(), !Q.latest);
    setItem('wtq-dnd', dndIcon(), dndLabel(), dndSub(), false);
    drawList();
  }

  /* ── listening to kt-walkie.js (events only, no fetch inside a listener) ─────────────────────── */
  w.addEventListener('kt:walkie-me', function (e) {
    Q.me = e.detail || null;
    if (Q.me && Q.me.unplayed !== undefined) { Q.unplayed = +Q.me.unplayed || 0; Q.latest = Q.me.latest || null; }
    if (!enabled()) { Q.unplayed = 0; Q.latest = null; if (Q.pop) Q.pop.close(); }
    paint();
  });
  w.addEventListener('kt:walkie-inbox', function (e) {
    var x = e.detail || {}; if (Q.playing) return;
    Q.unplayed = +x.unplayed || 0; Q.latest = x.latest || null; paint();
  });
  // The last chat changed (picked or talked to on the screen): the open popover follows.
  w.addEventListener('kt:walkie-last', function (e) {
    var k = e.detail && e.detail.key, C = Q.card; if (!k) return;
    remember(k);
    if (C && !Q.talking && all()[k] && (!C.sel || C.sel.key !== k)) { C.sel = all()[k]; paintCard(); }
  });
  var liveOff = null;
  w.addEventListener('kt:walkie-live', function (e) {
    var x = e.detail || {};
    clearTimeout(liveOff);
    if (x.on) { Q.live = { from: x.from, label: x.label, emergency: !!x.emergency }; paint(); return; }
    Q.live = null; paint();
    // The talk has finished: its "New" count (if it was not played here) arrives with the next inbox read.
    liveOff = setTimeout(function () { var W = wk(); if (W && W.refresh) W.refresh(); }, 1500);
  });
  // Leaving the Walkie-talkie screen (talks played or marked heard there): read the count again.
  var lastHash = location.hash;
  w.addEventListener('hashchange', function () {
    var was = lastHash; lastHash = location.hash;
    if (Q.pop && !Q.talking) Q.pop.close();   // went somewhere else: the popover goes too
    if (/^#walkie\b/.test(was) && !/^#walkie\b/.test(location.hash)) { var W = wk(); if (W && W.refresh) W.refresh(); }
    setTimeout(paint, 300);
  });
  // The gear (phone) comes and goes with the phone chrome; the bars are rebuilt on sign-in and View as.
  var syncQ = null;
  function queuePaint() { clearTimeout(syncQ); syncQ = setTimeout(paint, 250); }
  w.addEventListener('resize', queuePaint);
  function boot() {
    var W = wk(); if (W && W.me && W.me()) { Q.me = W.me(); }
    paint();
    try { new MutationObserver(function (ms) { for (var i = 0; i < ms.length; i++) { if (ms[i].addedNodes.length || ms[i].removedNodes.length) { queuePaint(); return; } } }).observe(d.body, { childList: true }); } catch (e) {}
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot); else boot();

  KT.walkieQuick = { open: open, paint: paint, pinTile: pinTile, state: function () { return { enabled: enabled(), unplayed: Q.unplayed, latest: Q.latest, live: Q.live, talking: Q.talking }; } };
})(window, document);
