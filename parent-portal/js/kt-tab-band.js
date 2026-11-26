/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — EVERY SUB-TAB STRIP LOOKS LIKE THE FAMILY RECORD'S (2026-10-20).

   Anthony: "i like how under families the bar that shows family, guardians, children with
   the gradient, we need to apply this to other sections that uses a subtab like this
   throughout the portal (full sweep here as well)".

   The portal draws sub-tabs a dozen different ways (underlines, pills, bordered buttons),
   each screen painting its own "current" state. Rewriting each is the fix that does not hold,
   so this recognises a tab strip and restyles it in place, on screens and in popups:

     - A STRIP is: role="tablist", data-kt-tabbar, or a row of 2+ buttons that are marked as
       tabs (role="tab", data-tab / data-t / data-pane / data-kt-inpage, or "tab" in their
       class). A plain row of action buttons is never touched.
     - The CURRENT tab: aria-selected / aria-current / an active|on|selected|current class;
       failing that, the one button whose own inline style differs from all the others
       (that is how most screens paint it). Re-read whenever the strip's buttons change, so
       the highlight follows the section you are in.
     - Look: the tinted band; the current tab filled with the navy gradient, white and bold;
       the rest quiet, highlighting on hover.
   The phone's bottom bar, the sidebar and the top bar are excluded; data-kt-noband opts out.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__ktTabBand) { return; }
  window.__ktTabBand = true;

  var GRAD = 'linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%)';
  /* td/th and the row-action cells: a tab strip never lives inside a table cell (2026-10-30, Forms manager: the
     row's hidden Edit/Deactivate/Delete buttons plus its ⋮ were read as a tab strip and the ⋮ drawn as a raised tab). */
  var EXCLUDE = '#kt-mobilenav, #appSidebar, #kt-topbar, .nav-links, [data-kt-noband], td, th, [data-kt-ka-card], .kt-ka-menu';

  function css() {
    if (document.getElementById('kt-tab-band-css')) { return; }
    var st = document.createElement('style');
    st.id = 'kt-tab-band-css';
    st.textContent = ''
      + '.kt-tabband{background:var(--kt-tab-band,#EEF3F8) !important;border:1px solid #E2E8F0 !important;border-radius:14px !important;padding:5px !important;gap:4px !important;box-shadow:none !important;}'
      + '.kt-tabband>.kt-tabbtn{border:1px solid transparent !important;border-radius:10px !important;background:transparent !important;color:#475569 !important;'
      + 'box-shadow:none !important;margin:0 !important;text-decoration:none !important;transition:background .15s,color .15s,box-shadow .15s;}'
      + '.kt-tabband>.kt-tabbtn:not(.kt-tab-on):hover{background:#FFFFFF !important;color:#1B3A63 !important;}'
      /* LIGHT, so it reads (2026-10-20: "band highlighting should be a lighter gradient as its
         hard to read"): pale blue gradient, navy text, a thin blue edge. */
      + '.kt-tabband>.kt-tabbtn.kt-tab-on{background:linear-gradient(135deg,var(--kt-tab-from,#E3EFFA) 0%,var(--kt-tab-to,#CFE2F5) 100%) !important;color:var(--kt-tab-ink,#12305A) !important;font-weight:800 !important;'
      + 'border-color:var(--kt-tab-edge,#9EC2E3) !important;box-shadow:0 2px 6px -3px rgba(27,58,99,.35) !important;}'
      + '.kt-tabband>.kt-tabbtn.kt-tab-on .kt-tab-ink{color:var(--kt-tab-ink,#12305A) !important;}'
      + '.kt-tabband>.kt-tabbtn:focus-visible{outline:2px solid #4A7FA6 !important;outline-offset:2px;}'
      /* COLOURS FADE, SIZES NEVER ANIMATE (2026-11-12, phone sweep). Tabs that are .kt-btn buttons carry the design
         pass's "transition: all" ([data-kt-pretty] .kt-btn), so when the band's sizes arrived each tab shrank from a
         44px button to a 34px tab in front of you - AI usage's strip visibly collapsed on every visit. Only colour,
         edge and shadow transition on a tab; :not(#kt-x) lends an ID's weight over the design rule. */
      + '.kt-tabband:not(#kt-x)>.kt-tabbtn,#appMain .kt-tabband:not(#kt-x)>.kt-tabbtn{transition:background-color .15s,color .15s,border-color .15s,box-shadow .15s !important;}'
      /* ONE TAB SIZE, EVERYWHERE (2026-11-01, Anthony: "under reports the subtabs are not consistent with the rest of
         the portal - full sweep to check for all other inconsistencies"). The band set the colours but each screen's own
         inline style set the tab's size, so the same band drew 18px tabs in the browser's default 13.33px font (Reports,
         KiddieTrac reports, Educator rewards, Notification readiness - no padding at all), 22px (Tickets), 30px (most) and
         38-41px (Settings, Server health, Licences, Audit log, Expenses). Measured across 190 screens. Now the band decides:
         32px tall (the portal's field height), 0 14px, 13px / 700 in the portal font (the current tab 800 as above), 6px
         between an icon/count and its label, nowrap. #appMain in the selector so the phone-wide 44px button floor and
         screens' "#appMain button" rules do not win; [hidden] tabs stay hidden. A strip that is a compact control drawn
         to its card, not a row of sub-tabs, keeps its own size with data-kt-tabsize="own" (Feature flags' Auto|On|Off).
         Width is the screen's choice: a strip on its own line spans the content (most screens: display:flex), one that
         sits in a toolbar beside a search box or filter hugs its tabs (inline-flex) - that is layout, not tab style. */
      + '.kt-tabband:not([data-kt-tabsize="own"])>.kt-tabbtn:not([hidden]),#appMain .kt-tabband:not([data-kt-tabsize="own"])>.kt-tabbtn:not([hidden]){'
      + 'display:inline-flex;align-items:center !important;justify-content:center;gap:6px;box-sizing:border-box !important;'
      + 'height:auto !important;min-height:32px !important;max-height:none !important;min-width:0 !important;padding:0 14px !important;'
      + 'font-family:inherit !important;font-size:13px !important;line-height:1.2 !important;letter-spacing:normal !important;white-space:nowrap !important;cursor:pointer;'
      + 'border-radius:10px !important;}'
      + '.kt-tabband:not([data-kt-tabsize="own"])>.kt-tabbtn:not(.kt-tab-on),#appMain .kt-tabband:not([data-kt-tabsize="own"])>.kt-tabbtn:not(.kt-tab-on){font-weight:700 !important;}'
      /* The current tab's 800 had only class weight, so kt-design-v22p65.css ("[data-kt-pretty] button:not(...)" x4,
         600 !important) drew the CURRENT tab lighter than the others on Reports, Tickets and every pretty screen.
         :not(#kt-x) lends it an ID's weight, in popups too. */
      + '.kt-tabband:not(#kt-x)>.kt-tabbtn.kt-tab-on{font-weight:800 !important;}'
      /* A tab's own icon/count spacing came from a margin as well as the gap; the gap is the one spacing now. */
      + '.kt-tabband:not([data-kt-tabsize="own"])>.kt-tabbtn>*{margin:0 !important;}'
      /* ONE ROW THAT SCROLLS, WITH ARROWS - EVERY SCREEN SIZE (2026-11-06, Anthony: "when editing a user the substabs are
         many use the scroll bar forward and back arrow to manage this bit - full sweep"). Phones have had this since
         2026-10-25 (Forms Manager's tabs filled a third of Safia's screen - two rows of 44px pills); on a computer a long
         strip still wrapped - the user record's ten tabs took two rows. Now every band is one row: the scrollbar is hidden,
         and the side that has more tabs shows a round arrow button (‹ ›) that slides the band along. #appMain in the
         selector so the phone-wide 44px button floor (an ID rule) does not win. */
      + '.kt-tabband,#appMain .kt-tabband{flex-wrap:nowrap !important;max-width:100% !important;min-width:0 !important;overflow-x:auto !important;overflow-y:hidden !important;-webkit-overflow-scrolling:touch;scrollbar-width:none;align-items:center !important;}'
      + '.kt-tabband::-webkit-scrollbar{display:none;}'
      + '.kt-tabband>.kt-tabbtn,#appMain .kt-tabband>.kt-tabbtn{flex:0 0 auto !important;white-space:nowrap !important;}'
      /* THE ARROWS ARE REAL BUTTONS BESIDE THE BAND (2026-11-06, Anthony: "look how the arrows back and forward look and are
         presented - full sweep to check for cosmetic issues here and resolve"). The first version drew them inside the
         scrolling band as sticky pseudo-elements over a fade: they did not sit flush with the band's ends, so tab text
         showed on both sides of each arrow ("c ‹ ct", "Clo › /"). Now an overflowing band is wrapped as ‹ [band] ›:
         two round buttons outside the scrolling area, so nothing ever slides under them. A band that fits is never
         wrapped; the arrows hide again if it stops overflowing, and the one at the end you have reached is dimmed. */
      + '.kt-tbwrap{display:flex;align-items:center;gap:6px;min-width:0;max-width:100%;box-sizing:border-box;}'
      + '.kt-tbwrap.kt-tbwrap-inline{display:inline-flex;}'
      /* :not(#kt-x) lends an ID's weight: a screen's own margin rule for its strip (the user record's full-bleed bar,
         ".modal.kt-record-modal .kt-rtabbar", !important) otherwise kept its -16px margins inside the wrapper and pushed
         the left arrow off the popup. The wrapper carries those margins instead. */
      + '.kt-tbwrap>.kt-tabband:not(#kt-x),#appMain .kt-tbwrap>.kt-tabband:not(#kt-x){flex:1 1 auto !important;min-width:0 !important;margin:0 !important;}'
      /* A band drawn edge to edge (negative side margins): the wrapper takes its tint and bottom line, so the strip still
         spans the popup, and the arrows sit 8px in from its edges. */
      + '.kt-tbwrap.kt-tbwrap-bleed>.kt-tabband:not(#kt-x){border-bottom-color:transparent !important;}'
      + '.kt-tbwrap>.kt-tbarrow,#appMain .kt-tbwrap>.kt-tbarrow{flex:0 0 30px !important;width:30px !important;height:30px !important;min-width:30px !important;'
      + 'min-height:30px !important;max-height:30px !important;padding:0 !important;margin:0 !important;border-radius:50% !important;border:1px solid #CBD5E1 !important;'
      + 'background:#FFFFFF !important;color:#1B3A63 !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;'
      + 'box-shadow:0 1px 2px rgba(15,23,42,.08) !important;cursor:pointer;line-height:0 !important;font-size:0 !important;transition:background .15s,border-color .15s,opacity .15s;}'
      + '.kt-tbwrap>.kt-tbarrow:hover:not([disabled]){background:#EEF3F8 !important;border-color:#9EC2E3 !important;}'
      + '.kt-tbwrap>.kt-tbarrow:focus-visible{outline:2px solid #4A7FA6 !important;outline-offset:2px;}'
      + '.kt-tbwrap>.kt-tbarrow[disabled]{opacity:.35 !important;cursor:default !important;box-shadow:none !important;}'
      + '.kt-tbwrap>.kt-tbarrow svg{width:14px;height:14px;display:block;pointer-events:none;}'
      + '.kt-tbwrap:not(.kt-tb-over)>.kt-tbarrow{display:none !important;}'
      /* ON A PHONE: a slimmer band and 34px tabs. The standard's selectors are listed too, so this (later, same weight)
         still sets the phone's tab. */
      + '@media (max-width:600px){'
      + '.kt-tabband,#appMain .kt-tabband{padding:4px !important;border-radius:12px !important;}'
      + '.kt-tbwrap{gap:4px;}'
      /* ONE BAND IN POPUPS TOO (2026-11-24, Anthony: "in mobile under leads and going into the lead the subtab scrolling
         looks different than what we designed in the audit logs"). A record popup's tab bar is drawn edge to edge
         (negative margins, square, 8px 12px), so the wrapper took the bleed look: a flat 51px slab with the arrows
         inside it, stopping short of the popup's right edge. On a phone every strip is the Audit log band - rounded
         pill, 1px line, 4px padding, 44px tall, arrows outside - whether or not it overflows. Desktop keeps its bar. */
      + '.kt-tbwrap.kt-tbwrap-bleed{margin-left:0 !important;margin-right:0 !important;padding-left:0 !important;padding-right:0 !important;background:transparent !important;border-bottom:0 !important;}'
      + '.kt-tbwrap.kt-tbwrap-bleed>.kt-tabband:not(#kt-x),.modal.kt-record-modal .kt-rtabbar.kt-tabband:not(#kt-x){border-radius:12px !important;padding:4px !important;border:1px solid #E2E8F0 !important;}'
      + '.modal.kt-record-modal .kt-rtabbar.kt-tabband:not(#kt-x){margin-left:0 !important;margin-right:0 !important;}'
      + '.kt-tabband>.kt-tabbtn,#appMain .kt-tabband>.kt-tabbtn,'
      + '.kt-tabband:not([data-kt-tabsize="own"])>.kt-tabbtn:not([hidden]),#appMain .kt-tabband:not([data-kt-tabsize="own"])>.kt-tabbtn:not([hidden]){flex:0 0 auto !important;white-space:nowrap !important;height:34px !important;min-height:34px !important;padding:0 12px !important;font-size:13px !important;border-radius:9px !important;}'
      + '}';
    document.head.appendChild(st);
  }

  function isTabButton(b) {
    if (!b || (b.tagName !== 'BUTTON' && b.tagName !== 'A' && b.getAttribute('role') !== 'tab')) { return false; }
    if (b.getAttribute('role') === 'tab') { return true; }
    /* data-kt-inpage is NOT a tab marker (2026-11-03, Anthony: "look at the curriculum section and you will see the
       cosmetic issues"). It only tells the shell a control is not browser-back, and cards, list rows and action chips
       carry it too - the curriculum unit grid and a week's activity boxes were adopted as tab strips (grey band,
       one-line 34px "tabs", text running across each other), and so were Sales' Edit / Play / Remove chips. */
    if (b.hasAttribute('data-tab') || b.hasAttribute('data-t') || b.hasAttribute('data-pane')) { return true; }
    /* Any data-…tab marker (2026-10-23): screens name their own - data-sh-tab (Server
       health), data-sv-tab, data-hc-tab, data-eatab, data-sub-tab, data-agtab… - and only the
       four spellings above were known, so those strips kept their own underline look. */
    for (var i = 0; i < b.attributes.length; i++) {
      var n = b.attributes[i].name;
      if (/^data-(?:[a-z0-9]+-)*[a-z0-9]*tabs?$/.test(n) && n.indexOf('table') === -1) { return true; }
    }
    return /(^|[\s_-])tabs?([\s_-]|$)|tab-?btn|tab-?link|-tab\b|\btab-/i.test((b.className || '').toString());
  }

  function buttonsOf(strip) {
    return [].filter.call(strip.children, function (k) { return k.tagName === 'BUTTON' || k.tagName === 'A' || k.getAttribute('role') === 'tab'; });
  }

  function isStrip(el) {
    if (!el || el.nodeType !== 1) { return false; }
    /* AN EXPLICIT TAB STRIP INSIDE A NO-BAND AREA (2026-11-24 sub-tab sweep). A screen puts data-kt-noband on its wrapper so
       its own chip rows are left alone (Play Spark); that also hid its real tab strip, which then wrapped and scrolled
       differently from every other screen's. A row that says data-kt-tabbar itself is a tab strip wherever it is; only
       data-kt-noband on the row itself (or the bars/cells in EXCLUDE) still opts it out. */
    var optIn = el.hasAttribute('data-kt-tabbar') && !el.hasAttribute('data-kt-noband');
    if (el.closest && el.closest(optIn ? EXCLUDE.replace('[data-kt-noband], ', '') : EXCLUDE)) { return false; }
    var btns = buttonsOf(el);
    if (btns.length < 2) { return false; }
    // Tabs only: the row must be (almost) nothing but its buttons.
    if (btns.length < el.children.length - 1) { return false; }
    if (el.getAttribute('role') === 'tablist' || el.hasAttribute('data-kt-tabbar')) { return true; }
    // A row the screen itself calls a tab strip (kt-subtabs / kt-tabs), e.g. Server health.
    if (/(^|\s)kt-(sub)?tabs(\s|$)/.test((el.className || '').toString())) { return true; }
    var marked = btns.filter(isTabButton).length;
    if (marked >= 2 && marked >= btns.length - 1) { return true; }
    return looksLikeToggle(el, btns);
  }

  /* An UNMARKED segmented toggle ("Active | Archived", "Active users | Invited | De-boarded"):
     only buttons, 2-12 of them, short labels that are not actions, and exactly one painted
     differently from the rest (the current one). An action row ("Export · Print · Email")
     fails the label test; a row with no single odd one out fails the last test. */
  var ACTION = /^(add|save|export|print|delete|remove|send|email|new|edit|cancel|close|download|upload|invite|create|import|apply|clear|reset|submit|back|next|previous|prev|refresh|sync|copy|view all|show|hide|open|run|generate|book|pay|charge|void|approve|reject|archive|restore)\b/i;
  function looksLikeToggle(el, btns) {
    if (btns.length < 2 || btns.length > 12 || el.children.length !== btns.length) { return false; }
    if (el.closest('.modal-footer, [class*="footer"], [class*="actions"]')) { return false; }
    for (var i = 0; i < btns.length; i++) {
      var txt = (btns[i].textContent || '').replace(/[^\p{L}\p{N}\s&/-]/gu, '').trim();
      if (!txt || txt.length > 28 || ACTION.test(txt)) { return false; }
    }
    return !!currentOf(btns);
  }

  var OURS = /\bkt-tab(btn|-on)\b/g;
  function own(b) {
    /* What the screen itself set, without our classes. By VALUE, not by the style text (2026-10-28, Anthony: "the
       subtabs do not highlight so sometimes you don't know which subtab you are in"). A screen that switches tabs with
       el.style.color = ... makes the browser rewrite that button's style text in long form, so the tab you left and the
       tab you are on no longer matched the untouched ones and no single "odd one out" was left - no highlight at all
       (Audit log > Payment integrations on a phone). */
    var st = b.style || {};
    return ((b.className || '').toString().replace(OURS, '').trim()) + '|' + [st.color, st.backgroundColor, st.backgroundImage,
      st.borderBottomColor, st.borderBottomStyle, st.borderLeftColor, st.borderTopColor, st.fontWeight, st.opacity, st.boxShadow].join('|');
  }
  /* A tab that is a link to a screen ("#audit-logs", data-hash) is current when it is where you are. */
  function hashOf(b) {
    var h = b.getAttribute('data-hash') || '';
    if (!h) { var href = b.getAttribute('href') || ''; if (href.charAt(0) === '#') h = href; }
    return h.replace(/^#/, '').split('?')[0];
  }

  function currentOf(btns) {
    for (var i = 0; i < btns.length; i++) {
      var b = btns[i];
      if (b.getAttribute('aria-selected') === 'true' || b.getAttribute('aria-pressed') === 'true' || b.getAttribute('aria-current') === 'page' || b.getAttribute('aria-current') === 'true') { return b; }
    }
    for (var j = 0; j < btns.length; j++) {
      if (/(^|\s)(active|is-active|on|selected|current|kt-active)(\s|$)/.test((btns[j].className || '').toString().replace(OURS, ''))) { return btns[j]; }
    }
    var here = String(location.hash || '').replace(/^#/, '').split('?')[0];
    if (here) {
      var byHash = btns.filter(function (b) { return hashOf(b) === here; });
      if (byHash.length === 1) { return byHash[0]; }
    }
    // The odd one out: the screen painted the current tab with a different inline style.
    var counts = {};
    btns.forEach(function (b) { var k = own(b); counts[k] = (counts[k] || 0) + 1; });
    var odd = btns.filter(function (b) { return counts[own(b)] === 1; });
    if (odd.length === 1 && btns.length >= 2 && Object.keys(counts).length === 2) { return odd[0]; }
    /* Every tab styled differently (always the case with two): the current one is the one the
       screen marked - a coloured underline or edge, a fill, or the darkest text. Unique best
       score wins; a tie decides nothing (2026-10-23, Server health's two tabs). */
    var best = null, top = 0, tie = false;
    function lum(txt) {   // 0 (black) .. 255 (white); null when there is no solid colour
      if (!txt) return null;
      var m = /#([0-9a-f]{6}|[0-9a-f]{3})\b/i.exec(txt), r, g, bl;
      if (m) { var h = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1]; r = parseInt(h.slice(0, 2), 16); g = parseInt(h.slice(2, 4), 16); bl = parseInt(h.slice(4, 6), 16); }
      else { m = /rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?/i.exec(txt); if (!m || (m[4] !== undefined && +m[4] === 0)) return null; r = +m[1]; g = +m[2]; bl = +m[3]; }
      return r * 0.3 + g * 0.59 + bl * 0.11;
    }
    /* The LAST matching declaration is the one in force (2026-11-13 role audit: Users' "Active users | Invited" for a
       director read "border:none" and never the "border-bottom:2px solid ..." after it - two tabs, no highlight). */
    function decl(st, re) { var g = new RegExp(re.source, 'g'), m, last = ''; while ((m = g.exec(st))) { last = m[1]; } return last; }
    /* var(--brand-blue,#1F6080) -> the value it stands for, so a tab painted with the brand colour still scores. */
    var rootCs = null;
    function unvar(st) {
      return st.replace(/var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*))?\)/g, function (all, name, fb) {
        try { rootCs = rootCs || getComputedStyle(document.documentElement); } catch (e) {}
        var v = rootCs ? String(rootCs.getPropertyValue(name) || '').trim() : '';
        return v || (fb || '').trim();
      });
    }
    btns.forEach(function (b) {
      var st = unvar((b.getAttribute('style') || '').toLowerCase()), sc = 0, so = b.style || {};
      var bd = decl(st, /(?:^|;)\s*border(?:-bottom|-left)?\s*:([^;]*)/);
      var bdl = /transparent|none|\b0(px)?\b(?!\.)/.test(bd) && !/\d+px/.test(bd) ? null : lum(bd);
      // Long-form style text ("border-color: ...") - read the properties themselves.
      if (bdl === null && (so.borderBottomStyle === 'solid' || so.borderLeftStyle === 'solid')) {
        var edge = so.borderBottomStyle === 'solid' ? so.borderBottomColor : so.borderLeftColor;
        if (edge && !/transparent|rgba\([^)]*,\s*0\)/.test(edge)) bdl = lum(edge);
      }
      if (bdl !== null && bdl < 200) sc += 3;                         // a coloured underline / edge
      var bgl = lum(decl(st, /(?:^|;)\s*background(?:-color)?\s*:([^;]*)/));
      if (bgl !== null && bgl < 200) sc += 2;                         // a filled tab
      var cl = lum(decl(st, /(?:^|;)\s*color\s*:([^;]*)/));
      if (cl !== null && cl < 60) sc += 1;                            // the darkest text
      if (sc > top) { top = sc; best = b; tie = false; } else if (sc === top && sc > 0) { tie = true; }
    });
    return (best && !tie) ? best : null;
  }

  function paint(strip) {
    var btns = buttonsOf(strip);
    var cur = currentOf(btns);
    btns.forEach(function (b) {
      if (!b.classList.contains('kt-tabbtn')) { b.classList.add('kt-tabbtn'); }
      var on = b === cur;
      if (b.classList.contains('kt-tab-on') !== on) { b.classList.toggle('kt-tab-on', on); }
      /* White text on the filled tab - but not inside a badge that has its own background
         (a count pill keeps its colours, or its number would vanish white-on-white). */
      [].forEach.call(b.querySelectorAll('*'), function (k) {
        var mine = false;
        if (on) {
          mine = true;
          for (var n = k; n && n !== b; n = n.parentElement) {
            var cs = getComputedStyle(n);
            if ((cs.backgroundImage && cs.backgroundImage !== 'none') || !/rgba\([^)]*,\s*0\)$|transparent/.test(cs.backgroundColor)) { mine = false; break; }
          }
        }
        if (k.classList.contains('kt-tab-ink') !== mine) { k.classList.toggle('kt-tab-ink', mine); }
      });
    });
  }

  /* ON A PHONE the band scrolls sideways (2026-10-26). Keep the current tab in view - only the band's
     own scrollLeft moves, never the page - and mark which sides have more tabs (the fade above). */
  var lastCur = typeof WeakMap === 'function' ? new WeakMap() : null;
  var wasAt = {};
  /* WHERE A BAND IS HEADING (2026-11-12, Anthony's phone video of the Audit log: "still not smooth"). A tapped tab
     slides to the middle; ~0.4s later the Audit log moves the band in under the new tab's banner, and a moved band is
     put back where it was (keepPlace). "Where it was" came from the band's scroll events - and while the phone is busy
     drawing the new tab those arrive late, so the band was put back where it had been a few frames into its slide:
     it snapped back and stayed off-centre. While a slide is under way the band is put back where it is GOING. */
  function goal(strip, to) { strip.__ktGoal = to; strip.__ktGoalUntil = Date.now() + 1200; }
  function placeOf(strip) {
    if (strip.__ktGoalUntil > Date.now()) { return strip.__ktGoal; }
    return strip.__ktLeft > 2 ? strip.__ktLeft : null;
  }
  function sigOf(strip) {
    return String(location.hash || '').split('?')[0] + '|' + [].map.call(strip.children, function (k) { return (k.textContent || '').trim().slice(0, 24); }).join('|');
  }
  function edges(strip) {
    var max = strip.scrollWidth - strip.clientWidth;
    var r = max > 2 && strip.scrollLeft < max - 2, l = max > 2 && strip.scrollLeft > 2;
    if (strip.classList.contains('kt-tb-r') !== r) { strip.classList.toggle('kt-tb-r', r); }
    if (strip.classList.contains('kt-tb-l') !== l) { strip.classList.toggle('kt-tb-l', l); }
    arrows(strip, max > 2, l, r);
  }

  /* ‹ [band] › - see the CSS above. The band is wrapped the first time it overflows (never before, so a strip that
     fits keeps its place in the screen's own layout exactly), and the wrapper takes over the band's outer margins and
     flex sizing so the row sits where the band sat. */
  var CHEV = { l: 'M10 3.5 5.5 8 10 12.5', r: 'M6 3.5 10.5 8 6 12.5' };
  function arrowBtn(strip, side) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'kt-tbarrow';
    b.setAttribute('aria-label', side === 'l' ? 'Earlier tabs' : 'More tabs');
    b.setAttribute('data-kttip', side === 'l' ? 'Earlier tabs' : 'More tabs');
    // Not an action for the icon-button pass, and fine in a view-only record (screen-admin viewOnlyLock).
    b.setAttribute('data-kt-iconized', '1');
    b.setAttribute('data-kt-view-ok', '1');
    b.setAttribute('data-kt-nospin', '1');
    b.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="' + CHEV[side] + '" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    b.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      strip.__ktTouchAt = Date.now();   // stays where it is put (fit)
      var by = Math.max(80, Math.round(strip.clientWidth * 0.7)) * (side === 'l' ? -1 : 1);
      goal(strip, Math.max(0, Math.min(strip.scrollWidth - strip.clientWidth, strip.scrollLeft + by)));
      try { strip.scrollBy({ left: by, behavior: 'smooth' }); } catch (e2) { strip.scrollLeft += by; }
    });
    return b;
  }
  function arrows(strip, over, l, r) {
    var w = strip.__ktWrap;
    /* The screen moved the band out of its wrapper (2026-11-12, phone sweep): the wrapper stayed behind holding
       only its two arrows. It holds nothing else, so it goes. */
    if (w && w !== strip.parentElement) {
      if (!w.contains(strip) && w.parentNode) { w.parentNode.removeChild(w); }
      w = strip.__ktWrap = null;
    }
    /* Already inside a wrapper that is not this band's own: a screen that copies its toolbar (cloneNode) copies the
       wrapper and its arrows, which have no handlers, and the band was wrapped again inside it - two arrows each side
       (Users, 2026-11-12). The copy is taken over: its dead arrows replaced by working ones. */
    if (!w && strip.parentElement && strip.parentElement.classList.contains('kt-tbwrap')) {
      w = strip.parentElement;
      [].slice.call(w.children).forEach(function (k) { if (k.classList.contains('kt-tbarrow')) { w.removeChild(k); } });
      w.__ktL = arrowBtn(strip, 'l');
      w.__ktR = arrowBtn(strip, 'r');
      w.insertBefore(w.__ktL, strip);
      w.appendChild(w.__ktR);
      strip.__ktWrap = w;
    }
    if (!w) {
      if (!over || !strip.parentElement) { return; }
      var cs = getComputedStyle(strip);
      w = document.createElement('div');
      w.className = 'kt-tbwrap' + (/inline/.test(cs.display) ? ' kt-tbwrap-inline' : '');
      ['marginTop', 'marginRight', 'marginBottom', 'marginLeft', 'flexGrow', 'flexShrink', 'flexBasis', 'alignSelf', 'order']
        .forEach(function (k) { w.style[k] = cs[k]; });
      if (cs.position === 'sticky') { w.style.position = 'sticky'; w.style.top = cs.top; w.style.zIndex = cs.zIndex; }
      if (parseFloat(cs.marginLeft) < 0 || parseFloat(cs.marginRight) < 0) {
        w.classList.add('kt-tbwrap-bleed');
        w.style.background = cs.backgroundColor;
        w.style.borderBottom = cs.borderBottomWidth + ' ' + cs.borderBottomStyle + ' ' + cs.borderBottomColor;
        w.style.paddingLeft = parseFloat(cs.marginLeft) < 0 ? '8px' : '0';
        w.style.paddingRight = parseFloat(cs.marginRight) < 0 ? '8px' : '0';
      }
      w.__ktL = arrowBtn(strip, 'l');
      w.__ktR = arrowBtn(strip, 'r');
      var keep = placeOf(strip);
      strip.parentElement.insertBefore(w, strip);
      w.appendChild(w.__ktL);
      w.appendChild(strip);
      w.appendChild(w.__ktR);
      strip.__ktWrap = w;
      if (keep != null && Math.abs(strip.scrollLeft - keep) > 2) { strip.scrollLeft = keep; }
    }
    if (w.classList.contains('kt-tb-over') !== over) { w.classList.toggle('kt-tb-over', over); }
    if (w.__ktL.disabled !== !l) { w.__ktL.disabled = !l; }
    if (w.__ktR.disabled !== !r) { w.__ktR.disabled = !r; }
  }
  /* A BAND NEVER WIDENS ITS COLUMN (2026-11-12, phone sweep: Resellers). A grid or flex item is at least as wide as
     its content unless it says min-width:0, and a one-row band's content is every tab side by side - so a band in a
     grid column (Resellers' .rs-split) pushed the column, and the page, past the screen. The band itself then never
     overflowed, so it got no arrows: "Brand Agencies People Commission Ap" ran off the right edge. Each grid/flex item
     between the band and #appMain may now shrink, so the band scrolls inside the column with its arrows. */
  function unpin(strip) {
    if (strip.__ktUnpin === strip.parentElement) { return; }
    strip.__ktUnpin = strip.parentElement;
    for (var n = strip, d = 0; n && n.parentElement && d < 8; n = n.parentElement, d++) {
      if (n.id === 'appMain' || n.parentElement.id === 'appMain' || /^(BODY|HTML)$/.test(n.parentElement.tagName)) { break; }
      var pd = getComputedStyle(n.parentElement).display;
      if (/grid|flex/.test(pd) && getComputedStyle(n).minWidth === 'auto') { n.style.minWidth = '0'; }
    }
  }

  function fit(strip) {
    try { unpin(strip); } catch (e) {}
    if (!strip.clientWidth) { return; }
    if (strip.scrollWidth > strip.clientWidth + 2) {
      var cur = strip.querySelector(':scope > .kt-tab-on');
      // Only when the current tab changes (or first shows), so a finger scrolling the band is not undone.
      var hidden = false;
      /* A band the person slid along (an arrow, a swipe, the wheel) stays where they put it until they pick a tab
         (2026-11-06): with the arrows on every screen, "re-centre the current tab 2 seconds later" undid each arrow
         click. Picking a tab clears __ktTouchAt (the click handler below), and that tab comes to the middle. */
      if (cur && lastCur && lastCur.get(strip) === cur && !strip.__ktTouchAt) {
        var ra = strip.getBoundingClientRect(), rb = cur.getBoundingClientRect();
        hidden = rb.left < ra.left + 36 || rb.right > ra.right - 36;
      }
      if (cur && (!lastCur || lastCur.get(strip) !== cur || hidden)) {
        /* THE TAPPED TAB COMES TO THE MIDDLE (2026-10-30, Anthony: "when click on subtabs - it highlights but doesn't move
           the subtab to the focus and it can stay to the far right or to the far left"). This only nudged a tab until it was
           48px inside the edge, so a tab tapped at either end stayed there, half under the arrow fade. Now the band slides
           the current tab to its centre (as far as the band can scroll): smoothly when you switch, at once when the band
           first appears or was rebuilt by the screen. */
        var first = !lastCur || !lastCur.has(strip);
        if (lastCur) { lastCur.set(strip, cur); }
        var sig = sigOf(strip);
        if (first && wasAt[sig] != null && Math.abs(strip.scrollLeft - wasAt[sig]) > 2) { strip.scrollLeft = wasAt[sig]; first = false; }
        var a = strip.getBoundingClientRect(), b = cur.getBoundingClientRect();
        var max = strip.scrollWidth - strip.clientWidth;
        var to = Math.max(0, Math.min(max, Math.round(strip.scrollLeft + (b.left - a.left) - (a.width - b.width) / 2)));
        if (Math.abs(to - strip.scrollLeft) > 2) {
          strip.__ktFitting = true; setTimeout(function () { strip.__ktFitting = false; strip.__ktLeft = strip.scrollLeft; }, 900);
          goal(strip, to);
          if (first || !strip.scrollTo) { strip.scrollLeft = to; }
          else { try { strip.scrollTo({ left: to, behavior: 'smooth' }); } catch (e) { strip.scrollLeft = to; } }
        }
      }
    }
    edges(strip);
  }

  /* ONE ROW, WHATEVER THE SCREEN DREW (2026-11-12). RSVP lays its tabs out as a grid and Curriculum as a plain block
     of inline buttons; the band's nowrap and sideways scroll only work on a flex row, so both showed two rows of tabs
     on a phone with no arrows. The band is a flex row whatever the screen used (an inline grid or block becomes
     inline-flex, so its width stays the screen's choice). */
  function oneRow(strip) {
    var d = getComputedStyle(strip).display;
    if (/^(block|inline-block|inline|grid|inline-grid|flow-root)$/.test(d)) {
      strip.style.setProperty('display', /^inline/.test(d) ? 'inline-flex' : 'flex', 'important');
    }
  }

  function watchTabs(strip) {
    if (!strip.__ktRo) { return; }
    [].forEach.call(strip.children, function (k) { if (!k.__ktRoW) { k.__ktRoW = true; try { strip.__ktRo.observe(k); } catch (e) {} } });
  }

  function adopt(strip) {
    if (strip.__ktBand) { oneRow(strip); paint(strip); fit(strip); return; }
    strip.__ktBand = true;
    css();
    strip.classList.add('kt-tabband');
    oneRow(strip);
    paint(strip);
    fit(strip);
    /* A BAND RE-MEASURES WHEN ITS SIZE CHANGES (2026-11-12, Anthony: "look at all subtab scrolling bars in mobile").
       A band adopted inside a hidden pane measured 0 wide, so fit() stopped there and nothing ran it again when the
       pane opened: Resellers' tabs ran off the right edge with no arrows. Showing, hiding or resizing a band now
       measures it again. */
    /* The tabs are watched too: a count that fills in after the band was measured (AI usage's credits), or an emoji
       font arriving, widens the tabs while the band's own box stays the same size. */
    if (typeof ResizeObserver === 'function') {
      try {
        var rt = null;
        strip.__ktRo = new ResizeObserver(function () {
          if (rt) { return; }
          var go = function () { rt = null; if (strip.isConnected) { fit(strip); breathe(strip); } };
          // Called on window: a detached requestAnimationFrame throws "Illegal invocation".
          rt = window.requestAnimationFrame ? window.requestAnimationFrame(go) : setTimeout(go, 16);
        });
        strip.__ktRo.observe(strip);
        watchTabs(strip);
      } catch (e) {}
    }
    strip.addEventListener('scroll', function () { edges(strip); wasAt[sigOf(strip)] = strip.__ktGoalUntil > Date.now() ? strip.__ktGoal : strip.scrollLeft; remember(strip); }, { passive: true });
    // A finger on the band takes over from any slide under way.
    ['touchstart', 'pointerdown', 'wheel'].forEach(function (t) { strip.addEventListener(t, function () { strip.__ktTouchAt = Date.now(); strip.__ktGoalUntil = 0; }, { passive: true }); });
    var t = null;
    try {
      new MutationObserver(function (list) {
        /* A SCREEN THAT REDRAWS ITS TABS (2026-11-12, Anthony's phone video of the Message Centre). Replacing a band's
           buttons sends its sideways scroll back to the first tab, and the new buttons were drawn for a frame as plain
           44px buttons before they were restyled. This callback runs before the frame is painted: the band is put
           back where it was and the new buttons are styled right here, so fit() below only has to bring the tapped
           tab to the middle - from where the band was, not from the first tab. */
        var redrawn = false;
        for (var i = 0; i < list.length; i++) { if (list[i].type === 'childList' && list[i].target === strip && list[i].addedNodes.length) { redrawn = true; break; } }
        if (redrawn) {
          try { var at = placeOf(strip); if (at != null && Math.abs(strip.scrollLeft - at) > 2) { strip.scrollLeft = at; } } catch (e) {}
          try { paint(strip); } catch (e) {}
        }
        if (t) { return; }
        t = setTimeout(function () { t = null; watchTabs(strip); paint(strip); fit(strip); }, 0);
      }).observe(strip, { attributes: true, subtree: true, childList: true, characterData: true, attributeFilter: ['class', 'style', 'aria-selected', 'aria-current'] });
    } catch (e) {}
    // Click: the screen repaints its own state synchronously; read it right after.
    strip.addEventListener('click', function (e) {
      if (e.target !== strip) { strip.__ktTouchAt = 0; }   // a tap on a tab, not a swipe: bring it to the middle
      setTimeout(function () { paint(strip); fit(strip); }, 0);
      setTimeout(function () { if (strip.isConnected) { paint(strip); fit(strip); } }, 180);
      setTimeout(function () { if (strip.isConnected) { paint(strip); fit(strip); } }, 500);
    }, true);
  }

  /* ROOM UNDER THE BAND (2026-10-23, Anthony: "message centre, the table column bar and the
     sub tab bar are touching each other"). A screen that puts its table straight under the
     tab strip, with neither side carrying a margin, glued the band to the column header.
     Measured rather than a blanket margin: whatever is drawn next below the band (its next
     sibling, or its parent's) gets at least 12px of air; a screen that already spaces it is
     left alone. */
  function nextBelow(strip) {
    var e = strip, main = document.getElementById('appMain') || document.body;
    for (var i = 0; i < 6 && e && e !== main; i++) {
      for (var n = e.nextElementSibling; n; n = n.nextElementSibling) {
        var cs = getComputedStyle(n);
        if (cs.display === 'none' || cs.position === 'absolute' || cs.position === 'fixed' || !n.offsetHeight) { continue; }
        return n;
      }
      e = e.parentElement;
    }
    return null;
  }
  function breathe(band) {
    /* A band with arrows sits in its wrapper: the wrapper is what is drawn, and the band's next sibling is its own
       right arrow, so the gap under a wrapped band was never measured (2026-11-12, Sales > Files: 4px). */
    var strip = band.parentElement && band.parentElement.classList.contains('kt-tbwrap') ? band.parentElement : band;
    if (!strip.offsetHeight) { return; }
    var cs = getComputedStyle(strip);
    if (cs.position === 'absolute' || cs.position === 'fixed' || cs.position === 'sticky') { return; }
    var n = nextBelow(strip);
    if (!n) { return; }
    var a = strip.getBoundingClientRect(), b = n.getBoundingClientRect();
    if (b.top < a.top + a.height / 2) { return; }          // beside the band, not under it
    var gap = b.top - a.bottom;
    if (gap >= 8 || gap < -2) { return; }
    strip.style.marginBottom = Math.round((parseFloat(cs.marginBottom) || 0) + 12 - gap) + 'px';
  }

  function scan() {
    var cands = document.querySelectorAll('[role="tablist"], [data-kt-tabbar], [role="tab"], button[data-tab], button[data-t], button[data-pane], button[data-kt-inpage], [class*="tab"], #appMain button, .modal button');
    var seen = [];
    for (var i = 0; i < cands.length; i++) {
      var c = cands[i];
      /* A row whose OWN class names it a tab strip (kt-help-tabs, oh-tabs, fm-tabs...) is a candidate itself, not only
         its parent (2026-11-13 role audit: Help's "Guides | Training" links were never looked at - <a> children are
         not candidates and the parent is the whole page). "table" never counts. */
      var selfStrip = c.getAttribute('role') === 'tablist' || c.hasAttribute('data-kt-tabbar')
        || /(^|[\s_-])tabs(\s|$)|-tabs\b/i.test((c.className || '').toString());
      var strip = selfStrip ? c : c.parentElement;
      if (!strip || seen.indexOf(strip) !== -1) { continue; }
      seen.push(strip);
      try { if (isStrip(strip)) { adopt(strip); } } catch (e) {}
      if (selfStrip && c.parentElement && seen.indexOf(c.parentElement) === -1 && c.getAttribute('role') !== 'tablist' && !c.hasAttribute('data-kt-tabbar')) {
        seen.push(c.parentElement);
        try { if (isStrip(c.parentElement)) { adopt(c.parentElement); } } catch (e) {}
      }
    }
    [].forEach.call(document.querySelectorAll('.kt-tabband'), function (b) { try { breathe(b); } catch (e) {} });
  }


  /* ── SECTION HEADINGS AS THE SAME LIGHT BAND (2026-10-20) ─────────────────────────────
     The portal titles its sections with small grey capitals ("INCIDENTS", "DOCUMENTS",
     "HEALTH & CARE") that read as faint text when nothing tinted sits under them. A heading
     that is clearly a SECTION title gets the light band: bold, small, all capitals, no
     background of its own, spanning most of a container at least 360px wide (so a stat
     tile's caption is never one), not a field label (nothing to fill directly under it), and
     not inside a table, button, label, nav, tab strip or title bar. data-kt-nosechead opts out. */
  var SEC_SKIP = 'table, thead, th, td, label, button, a, nav, select, .kt-tabband, [role="tablist"], .modal-header, .kt-dlg-head, '
    + '#appSidebar, #kt-topbar, #kt-mobilenav, [data-kt-nosechead], .kt-sechead, '
    + '.kt-hero, .kt-page-hero, .kt-hero-auto, .page-header-v17, [data-kt-hero]';

  /* THE BAND IS FOR LIGHT SURFACES ONLY (2026-10-21). Anthony: "today at a glance in desktop
     when under educator has a light blue highlight in the hero banner". The educator Today
     banner is a hand-drawn dark gradient with a small capitals eyebrow ("TODAY AT A GLANCE")
     - which matched every rule above, so it was given the light band and showed as a pale
     strip across the banner. It is not a section heading on a page: it is a label on a
     coloured surface. Decided by what the label sits on, so it covers every banner, coloured
     card and callout, classed or inline-styled:
       - light text (it was written for a dark surface) - never;
       - the nearest painted ancestor is dark, or a gradient that is not light end to end - never. */
  function lumOf(c) {
    var m = String(c || '').match(/rgba?\(([^)]+)\)/);
    if (!m) { return null; }
    var p = m[1].split(',').map(parseFloat);
    if (p.length > 3 && p[3] < 0.15) { return null; }
    var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(p[0]) + 0.7152 * f(p[1]) + 0.0722 * f(p[2]);
  }
  function onColouredSurface(el) {
    var tl = lumOf(getComputedStyle(el).color);
    if (tl !== null && tl > 0.55) { return true; }
    for (var n = el.parentElement, d = 0; n && n !== document.body && d < 10; n = n.parentElement, d++) {
      var cs = getComputedStyle(n);
      if (cs.backgroundImage && /gradient/.test(cs.backgroundImage)) {
        var stops = cs.backgroundImage.match(/rgba?\([^)]+\)/g) || [];
        for (var k = 0; k < stops.length; k++) { var sl = lumOf(stops[k]); if (sl !== null && sl < 0.8) { return true; } }
        if (stops.length) { return false; }
      }
      var l = lumOf(cs.backgroundColor);
      if (l !== null) { return l < 0.8; }
      if (n.id === 'appMain') { break; }
    }
    return false;
  }
  function secCss() {
    if (document.getElementById('kt-sechead-css')) { return; }
    var st = document.createElement('style');
    st.id = 'kt-sechead-css';
    // A touch softer than a record's own section bands, so a sub-section reads as nested.
    st.textContent = '.kt-sechead{background:linear-gradient(90deg,#EEF4FB 0%,#F8FBFE 100%) !important;--kt-edge:#8DB4D8 !important;'
      + 'border-radius:8px !important;padding:6px 12px !important;color:#12305A !important;}';
    document.head.appendChild(st);
  }
  function caps(t) { return /[A-Z]{3}/.test(t) && t === t.toUpperCase(); }
  function isSectionHead(el) {
    if (el.__ktSh) { return false; }
    el.__ktSh = true;
    if (el.childElementCount > 1) { return false; }
    var t = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (t.length < 3 || t.length > 48) { return false; }
    var cs = getComputedStyle(el);
    if (!(cs.textTransform === 'uppercase' || caps(t))) { return false; }
    if ((parseInt(cs.fontWeight, 10) || 400) < 700 || parseFloat(cs.fontSize) > 15) { return false; }
    if ((cs.backgroundImage && cs.backgroundImage !== 'none') || !/rgba\([^)]*,\s*0\)$|transparent/.test(cs.backgroundColor)) { return false; }
    if ((parseFloat(cs.borderLeftWidth) || 0) > 0) { return false; }
    if (el.closest(SEC_SKIP)) { return false; }
    if (onColouredSurface(el)) { return false; }
    var nx = el.nextElementSibling;
    if (!nx || /^(INPUT|SELECT|TEXTAREA)$/.test(nx.tagName) || (nx.matches && nx.matches('label'))) { return false; }
    var par = el.parentElement;
    if (!par) { return false; }
    var pw = par.getBoundingClientRect().width, w = el.getBoundingClientRect().width;
    /* 360px kept every band off a phone, whose cards are ~330px inside (2026-10-30, Anthony: "the subtle colour bands
       on desktop is perfect but not the same on mobile view"): the floor follows the screen, never above 300 - the same cards are banded on a computer and a phone (a 330px insight card was banded only on a phone). */
    if (pw < Math.min(300, (window.innerWidth || 360) * 0.7) || w < pw * 0.6) { return false; }
    return true;
  }
  /* THE CARD UNDER A BAND STAYS WHITE (2026-10-30). On a computer a section card is white with the light band across
     its title. kt-mobile-tint.css washes every white card on a phone in one of six tints, which buried the band (the
     band and the wash are the same pale family) and made phones look unlike desktop. The nearest rounded card around a
     band is marked kt-sec-card, and the phone tint leaves those cards as desktop draws them. */
  function markCard(el) {
    for (var n = el.parentElement, d = 0; n && d < 4 && n.id !== 'appMain'; n = n.parentElement, d++) {
      if ((parseFloat(getComputedStyle(n).borderTopLeftRadius) || 0) >= 6) { n.classList.add('kt-sec-card'); return; }
    }
  }
  function secScan() {
    // A band already given to a label that turns out to sit on a coloured surface comes off.
    var had = document.querySelectorAll('.kt-sechead');
    for (var j = 0; j < had.length; j++) {
      try { if (had[j].closest('.kt-hero, .kt-page-hero, .page-header-v17, [data-kt-hero]') || onColouredSurface(had[j])) { had[j].classList.remove('kt-sechead'); } } catch (e) {}
    }
    var list = document.querySelectorAll('#appMain h3, #appMain h4, #appMain h5, #appMain h6, #appMain div, .modal h3, .modal h4, .modal h5, .modal div');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];
      if (el.__ktSh || el.offsetParent === null) { continue; }
      try { if (isSectionHead(el)) { secCss(); el.classList.add('kt-sechead'); markCard(el); } } catch (e) {}
    }
  }

  var tm = null;
  function schedule() { if (!tm) { tm = setTimeout(function () { tm = null; scan(); try { secScan(); } catch (e) {} }, 150); } }

  /* A NEW STRIP IS BANDED BEFORE IT IS SEEN (2026-10-28, Anthony: "screen is jumpy when moving
     from the subtabs in desktop view - full sweep"). The scan above runs 150ms after the page
     stops changing, so every strip a screen draws - or redraws on a tab click - was painted
     first as the screen built it (plain buttons, its own spacing) and restyled a few frames
     later into the band, a different height: everything under it jumped. Measured on Accounting
     AR/AP (1440px): on each tab click the strip was un-banded for 150-250ms and the banner
     moved 56px for a frame; its payments badge also redraws the whole strip a moment later.
     A MutationObserver callback runs before the next paint, so the strips inside what was just
     added are adopted right there. Only the added subtrees are looked at (at most 400 buttons
     per batch); the full scan still follows as before for anything this misses. */
  var QUICK = 'button, a[role="tab"], [role="tab"], [role="tablist"], [data-kt-tabbar]';
  /* A MOVED BAND KEEPS ITS PLACE (2026-11-08, Anthony's phone video of Audit log: "look when i scroll through the
     subtabs what happens - fix this throughout"). A browser sets an element's scroll back to 0 whenever it is taken
     out of the page and put back. Screens that keep one tab row and move it under each tab's new banner (Audit log,
     and anything the banner normaliser re-homes) therefore showed the band snapped to the first tab for a frame, then
     sliding back to the one picked. Every band remembers where it was (__ktLeft, from its own scroll events); when it
     reappears in an added subtree it is put back there before the frame is painted, and fit() takes it from there. */
  function remember(strip) {
    if (!strip.isConnected) { return; }
    var x = strip.scrollLeft;
    // A jump to 0 nobody asked for is the browser resetting a moved band, not a place to keep.
    if (x <= 2 && strip.__ktLeft > 2 && !(strip.__ktTouchAt && Date.now() - strip.__ktTouchAt < 1500) && !strip.__ktFitting) { return; }
    strip.__ktLeft = x;
  }
  function keepPlace(list) {
    for (var i = 0; i < list.length; i++) {
      var added = list[i].addedNodes;
      for (var j = 0; j < added.length; j++) {
        var n = added[j];
        if (n.nodeType !== 1) { continue; }
        var bands = n.classList && n.classList.contains('kt-tabband') ? [n] : (n.firstElementChild ? n.querySelectorAll('.kt-tabband') : []);
        for (var k = 0; k < bands.length; k++) {
          var b = bands[k];
          var at = placeOf(b);
          if (at != null && Math.abs(b.scrollLeft - at) > 2) { b.scrollLeft = at; }
        }
      }
    }
  }
  function quick(list) {
    try { keepPlace(list); } catch (e) {}
    try {
      var strips = [], budget = 400;
      for (var i = 0; i < list.length && budget > 0; i++) {
        var added = list[i].addedNodes;
        for (var j = 0; j < added.length && budget > 0; j++) {
          var n = added[j];
          if (n.nodeType !== 1 || !n.closest || !n.closest('#appMain, .modal')) { continue; }
          var c = n.matches(QUICK) ? [n] : [];
          if (n.firstElementChild) { c = c.concat([].slice.call(n.querySelectorAll(QUICK), 0, budget)); }
          budget -= c.length;
          for (var k = 0; k < c.length; k++) {
            var st = (c[k].getAttribute('role') === 'tablist' || c[k].hasAttribute('data-kt-tabbar')) ? c[k] : c[k].parentElement;
            if (st && !st.__ktBand && strips.indexOf(st) === -1) { strips.push(st); }
          }
        }
      }
      strips.forEach(function (st) { try { if (isStrip(st)) { adopt(st); } } catch (e) {} });
    } catch (e) {}
  }
  function boot() {
    try { new MutationObserver(function (list) { quick(list); schedule(); }).observe(document.body, { childList: true, subtree: true }); } catch (e) {}
    schedule();
    // A wider or narrower window changes which bands overflow, so which arrows show.
    window.addEventListener('resize', function () { [].forEach.call(document.querySelectorAll('.kt-tabband'), function (b) { try { edges(b); } catch (e) {} }); });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
