/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Branding Loader (v2, 2026-10-21: resellers + full runtime theme)

   /branding returns a resolved `theme` (App\Support\Brand: reseller -> agency override
   -> KiddieTrac). When the theme is WHITE-LABEL and it is for THIS session's agency (or,
   before sign-in, for the ?tenant= slug a branded app opened with), the portal is
   re-dressed at runtime - no rebuild, no source edits:

     - logos: every img[data-brand-logo] shows the brand's wordmark (or the brand NAME as
       text when a white-label brand has not uploaded a logo - never the KiddieTrac one);
     - colours: KiddieTrac's teal/blue family is rewritten to the brand's primary and
       accent, in the stylesheets once and in inline styles as screens draw them;
     - words: "KiddieTrac" in on-screen text and tooltips becomes the brand name (never
       inside email addresses or links, and not on KiddieTrac's own sales/platform screens);
     - tab title, favicon, theme colour and the install manifest follow the brand.

   KT.brand holds the theme; KT.brandName() is the name new code should print.
   Nothing changes for a session that is not white-label.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  const STORAGE_KEY = 'kt_branding';
  const TENANT_KEY = 'kt_tenant';            // ?tenant= from a white-label app build
  const CACHE_TTL_MS = 5 * 60 * 1000;
  const LOGO_SEL = '.brand-logo, [data-brand-logo], #brand-logo';
  const KT_WORDMARK = 'https://app.kiddietrac.com/logo-wordmark@2x.png';
  const KT_FAVICON = 'https://app.kiddietrac.com/favicon-32.png';
  // KiddieTrac's own screens keep the KiddieTrac name even inside a white-label agency.
  const INTERNAL_HASH = /^#?(sales|platform-|resellers|reseller-brand|admin-mrr|admin-features|prospect-ndas|mail-settings|maintenance|usage-spend|ai-usage|phone-voicemail|digest-status|marketing-site|security-alerts|server-health)/;

  let THEME = null;          // the active white-label theme, or null

  /* COPYRIGHT (2026-10-30). "© <this year> KiddieTrac Inc. All Rights Reserved." The year is read
     from the clock, so it turns over by itself on 1 January. Anything showing it wears
     .kt-copyright, which html.kt-wl (a white-label agency, set in applyLogos) hides: their portal
     carries their brand, so it must not assert ours. */
  window.KT = window.KT || {};
  /* THE COMPANY (2026-11-04): the legal name, the same as App\Support\Company on the server (platform_settings
     company.legal_name). Use KT.COMPANY.legalName wherever the business is named, never a typed copy. */
  window.KT.COMPANY = window.KT.COMPANY || { legalName: 'KiddieTrac Inc' };
  window.KT.copyrightText = function () { return '\u00a9 ' + new Date().getFullYear() + ' ' + window.KT.COMPANY.legalName + '. All Rights Reserved.'; };
  (function () {
    if (document.getElementById('kt-copyright-css')) return;
    var st = document.createElement('style'); st.id = 'kt-copyright-css';
    st.textContent = 'html.kt-wl .kt-copyright{display:none !important;}';
    (document.head || document.documentElement).appendChild(st);
  })();
  let COLOR_MAP = null;      // lowercase hex -> brand hex
  let observer = null;

  function apiBase() {
    return (window.KT && window.KT.Api && window.KT.Api.base) || 'https://api.kiddietrac.com/api/v1';
  }
  function absUrl(u) {
    if (!u) return '';
    if (/^(https?:|data:|blob:)/i.test(u)) return u;
    return apiBase().replace(/\/api\/v1\/?$/, '') + (u.charAt(0) === '/' ? '' : '/') + u;
  }

  /* ── colour helpers ───────────────────────────────────────────────── */
  function hex2rgb(h) { h = String(h || '').replace('#', ''); if (h.length !== 6) return null; return [0, 2, 4].map(function (i) { return parseInt(h.substr(i, 2), 16); }); }
  function rgb2hex(c) { return '#' + c.map(function (v) { v = Math.max(0, Math.min(255, Math.round(v))); return (v < 16 ? '0' : '') + v.toString(16); }).join(''); }
  function mix(h, toward, amt) { var a = hex2rgb(h), b = hex2rgb(toward); if (!a || !b) return h; return rgb2hex(a.map(function (v, i) { return v + (b[i] - v) * amt; })); }

  function lumOf(h) { var c = hex2rgb(h); if (!c) return 1; c = c.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; }
  function crWhite(h) { return 1.05 / (lumOf(h) + 0.05); }
  /* Darken toward black until white text reads (4.5:1), or lighten-dark text the same way. */
  function forWhiteText(h) { var x = h, k = 0; while (crWhite(x) < 4.5 && k++ < 20) x = mix(x, '#000000', 0.06); return x; }
  function readOn(h, bg) { var x = h, k = 0; const cr = function () { var a = lumOf(x), b = lumOf(bg); return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05); }; while (cr() < 7 && k++ < 20) x = mix(x, '#000000', 0.08); return x; }
  /* THE BRAND'S SECOND BUTTON COLOUR (2026-11-26, Anthony: "the dark orange buttons in starlight academy don't look
     good"). KiddieTrac's action colour is a teal family (#13B7CC and its darker steps, the teal->blue button
     gradient, the popup header's teal end); the map below sent all of it to the ACCENT and darkened steps of it, so a
     warm accent such as Starlight's amber came out as muddy dark orange on buttons, the Add button and every popup
     header. The teal family now maps to the primary's own lighter partner - the banner gradient's end (hero.to) when
     white text reads on it, else a lighter primary - darkened until white reads 4.5:1. The accent keeps the small
     highlights (KiddieTrac's green: the nav's active line, badges). */
  function brandPartner(t) {
    var P = t.primary_color, h = t.hero || {}, to = /^#[0-9a-f]{6}$/i.test(h.to || '') ? h.to : (/^#[0-9a-f]{6}$/i.test(t.hero_to || '') ? t.hero_to : null);
    if (to && to.toLowerCase() !== String(t.accent_color || '').toLowerCase() && crWhite(to) >= 3) return forWhiteText(to);
    return forWhiteText(mix(P, '#ffffff', 0.28));
  }
  /* THE SOFT TINT (2026-11-26, Anthony: "the default light blue colour used throughout including sidebar for white
     label should be changeable and for starlight should mimic its branding - very soft purple tint"). The brand's
     Soft tint setting, else 7% of its lighter brand colour on white; every step (sidebar, active item, row hover,
     tab band and its active tab) is that tint pulled toward the brand colour by a little more. KiddieTrac's own
     light blue is the CSS fallback of each var (kt-sidebar-brand.css, kt-tab-band.js, kt-consistency-polish.css). */
  function brandTint(t) {
    if (!t || !/^#[0-9a-f]{6}$/i.test(t.primary_color || '')) return null;
    var B = brandPartner(t), T = /^#[0-9a-f]{6}$/i.test(t.soft_tint || '') ? t.soft_tint : mix(B, '#ffffff', 0.93);
    var strong = mix(T, B, 0.08), tabTo = mix(T, B, 0.13);
    return { base: B, auto: mix(B, '#ffffff', 0.93), tint: T, tint2: mix(T, B, 0.03), border: mix(T, B, 0.12), hover: mix(T, B, 0.05),
      strong: strong, ink: readOn(t.primary_color, strong), edge: mix(T, B, 0.18), sep: mix(T, B, 0.14), row: mix(T, B, 0.05),
      band: mix(T, B, 0.03), tabFrom: mix(T, B, 0.06), tabTo: tabTo, tabInk: readOn(t.primary_color, tabTo), tabEdge: mix(T, B, 0.35) };
  }
  const TINT_VARS = { '--kt-tint': 'tint', '--kt-tint-2': 'tint2', '--kt-tint-border': 'border', '--kt-tint-hover': 'hover', '--kt-tint-strong': 'strong',
    '--kt-tint-ink': 'ink', '--kt-tint-edge': 'edge', '--kt-tint-sep': 'sep', '--kt-row-hover': 'row', '--kt-tab-band': 'band',
    '--kt-tab-from': 'tabFrom', '--kt-tab-to': 'tabTo', '--kt-tab-ink': 'tabInk', '--kt-tab-edge': 'tabEdge' };
  function applyTint(t) {
    const root = document.documentElement, v = t ? brandTint(t) : null;
    Object.keys(TINT_VARS).forEach(function (k) { if (v) root.style.setProperty(k, v[TINT_VARS[k]]); else root.style.removeProperty(k); });
    // The row-hover tint's switch (kt-consistency-polish.css): white-label only, never the plain portal.
    root.classList.toggle('kt-tinted', !!v);
  }

  function buildColorMap(t) {
    var P = t.primary_color, A = t.accent_color;
    if (!/^#[0-9a-f]{6}$/i.test(P || '') || !/^#[0-9a-f]{6}$/i.test(A || '')) return null;
    if (P.toLowerCase() === '#1f6080' && A.toLowerCase() === '#13b7cc') return null;   // KiddieTrac's own
    var B2 = brandPartner(t);
    return {
      '#1f6080': P, '#2d7ba8': mix(P, '#ffffff', 0.18), '#154057': mix(P, '#000000', 0.3),
      '#0c4a6e': mix(P, '#000000', 0.45), '#12315c': mix(P, '#000000', 0.5), '#081c41': mix(P, '#000000', 0.62),
      '#13b7cc': B2, '#159fb4': B2, '#0e7490': mix(B2, '#000000', 0.22), '#0891b2': mix(B2, '#000000', 0.1),
      '#e0f2fe': mix(P, '#ffffff', 0.88), '#ecfeff': mix(B2, '#ffffff', 0.92), '#cffafe': mix(B2, '#ffffff', 0.82),
      '#edf6fb': mix(P, '#ffffff', 0.93), '#e7f3fb': t.splash_bg || mix(P, '#ffffff', 0.9),
      // The rest of KiddieTrac's palette: the hero gradient's mid-blue and green, and the
      // teal/blue pairs used by toasts and What's new.
      '#2c8aac': mix(P, B2, 0.5), '#8ec73c': A, '#6cb241': A, '#4b9670': mix(P, B2, 0.5), '#29799e': P,
      '#0e7c90': mix(B2, '#000000', 0.22), '#1f6fb2': mix(P, '#ffffff', 0.1), '#2563a8': P,
      /* POPUP HEADERS WEAR THE BRAND (2026-11-06, Anthony: "match the new message to the white label colour scheme -
         full sweep"). The one popup header (navy #1B3A63 -> #265B7A -> teal #2F8C86, teal line #7FD3CC) is written
         into ~24 files and Shell.Modal's CSS; none of it was in this map, so a white-label agency got KiddieTrac's
         colours on every popup. Mapped here, every copy follows: dark primary -> primary -> darkened accent (kept dark
         enough for the white title), and a light accent line. */
      '#1b3a63': mix(P, '#000000', 0.35), '#265b7a': P, '#2f8c86': B2, '#7fd3cc': mix(A, '#ffffff', 0.45),
    };
  }
  /* rgb() AS WELL AS #hex (2026-11-06). The browser keeps a colour set from script (el.style.x = '#159FB4', or
     Dom.el's style string) and every colour read back from a stylesheet's CSSOM as "rgb(21, 159, 180)", so a map of
     hex codes missed all of them: on a white-label agency the new-message popup kept KiddieTrac's navy header and
     teal Send button. Both spellings are matched now; an rgb() colour keeps its alpha. */
  const HEX_RE = /#(1f6080|2d7ba8|154057|0c4a6e|12315c|081c41|13b7cc|159fb4|0e7490|0891b2|e0f2fe|ecfeff|cffafe|edf6fb|e7f3fb|2c8aac|8ec73c|6cb241|4b9670|29799e|0e7c90|1f6fb2|2563a8|1b3a63|265b7a|2f8c86|7fd3cc)\b|rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+%?)\s*)?\)/gi;
  let RGB_MAP = null;        // "r,g,b" of a KiddieTrac colour -> brand hex
  function recolorStr(s) {
    if (!COLOR_MAP) return s;
    if (!RGB_MAP) {
      RGB_MAP = {};
      Object.keys(COLOR_MAP).forEach(function (k) { const c = hex2rgb(k); if (c) RGB_MAP[c.join(',')] = COLOR_MAP[k]; });
    }
    return s.replace(HEX_RE, function (m, hex, cr, cg, cb, ca) {
      if (hex) return COLOR_MAP[m.toLowerCase()] || m;
      const to = RGB_MAP[(+cr) + ',' + (+cg) + ',' + (+cb)];
      if (!to) return m;
      if (ca == null) return to;
      const t = hex2rgb(to);
      return t ? 'rgba(' + t.join(', ') + ', ' + ca + ')' : m;
    });
  }

  const KT_ART_RE = /(login-wordmark|logo-wordmark|wordmark-large|icon-512)[^)]*\)/i;
  function recolorSheet(sheet) {
    if (!THEME || !sheet || sheet.__ktRecolored === THEME) return;
    let rules;
    try { rules = sheet.cssRules; } catch (e) { return; }          // cross-origin (fonts): skip
    sheet.__ktRecolored = THEME;
    (function walk(list) {
      for (let i = 0; i < list.length; i++) {
        const r = list[i];
        if (r.cssRules && !r.style) { walk(r.cssRules); continue; }
        if (!r.style) continue;
        for (let j = 0; j < r.style.length; j++) {
          const p = r.style[j], v = r.style.getPropertyValue(p);
          // A KiddieTrac logo painted as a background (the phone sign-in card, the boot intro).
          if (v && /^background/.test(p) && KT_ART_RE.test(v)) { r.style.setProperty(p, p === 'background' ? 'none' : 'none', 'important'); continue; }
          if (COLOR_MAP && v && HEX_RE.test(v)) { HEX_RE.lastIndex = 0; const nv = recolorStr(v); if (nv !== v) r.style.setProperty(p, nv, r.style.getPropertyPriority(p)); }
          HEX_RE.lastIndex = 0;
        }
      }
    })(rules);
  }

  /* ── words ────────────────────────────────────────────────────────── */
  // Not inside addresses or links: kiddietrac.com, @kiddietrac, /kiddietrac.
  const NAME_RE = /(?<![\w@.\/-])Kiddie ?[Tt]rac(?![\w-]*\.(?:com|ca|app|net)\b)(?![\w])/g;
  function brandName() { return THEME ? THEME.name : 'KiddieTrac'; }
  // KiddieTrac's own support addresses in on-screen text become the brand's (when it has one).
  const KT_MAIL_RE = /\b(?:info|support|help|hello)@kiddietrac\.com\b/gi;
  function renameStr(s) {
    s = s.replace(NAME_RE, brandName());
    const m = THEME && THEME.support_email;
    return m && !/@kiddietrac\.com$/i.test(m) ? s.replace(KT_MAIL_RE, m) : s;
  }
  function internalScreen() { return INTERNAL_HASH.test(window.location.hash || ''); }

  function sweep(root) {
    if (!THEME || !root) return;
    const doNames = !internalScreen();
    // Text
    if (doNames) {
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode: function (n) {
          const p = n.parentNode;
          if (!p || /^(SCRIPT|STYLE|TEXTAREA|INPUT|CODE|PRE)$/.test(p.nodeName) || (p.isContentEditable)) return NodeFilter.FILTER_REJECT;
          // KiddieTrac's own things on an agency page keep their name, e.g. its toll-free line (2026-10-22).
          if (p.closest && p.closest('[data-kt-keep-brand]')) return NodeFilter.FILTER_REJECT;
          return /Kiddie ?[Tt]rac|@kiddietrac\.com/i.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
        },
      });
      const hits = [];
      while (walker.nextNode()) hits.push(walker.currentNode);
      hits.forEach(function (n) { const v = renameStr(n.nodeValue); if (v !== n.nodeValue) n.nodeValue = v; });
    }
    if (root.querySelectorAll) {
      if (doNames) {
        root.querySelectorAll('[title],[placeholder],[aria-label],img[alt]').forEach(function (el) {
          ['title', 'placeholder', 'aria-label', 'alt'].forEach(function (a) {
            const v = el.getAttribute(a);
            if (v && /Kiddie ?[Tt]rac/.test(v)) el.setAttribute(a, renameStr(v));
          });
        });
      }
      // KiddieTrac's own artwork elements (boot intro, launch scene) never show for a brand.
      root.querySelectorAll('.kti-logo, .kti-glow, .ktls-logo').forEach(function (el) { el.style.display = 'none'; });
      root.querySelectorAll('[style*="wordmark"], [style*="icon-512"]').forEach(function (el) {
        if (KT_ART_RE.test(el.getAttribute('style') || '')) el.style.backgroundImage = 'none';
      });
      if (COLOR_MAP) {
        const styled = root.matches && root.matches('[style]') ? [root] : [];
        root.querySelectorAll('[style]').forEach(function (el) { styled.push(el); });
        styled.forEach(function (el) {
          const s = el.getAttribute('style');
          if (s && HEX_RE.test(s)) { HEX_RE.lastIndex = 0; const ns = recolorStr(s); if (ns !== s) el.setAttribute('style', ns); }
          HEX_RE.lastIndex = 0;
        });
      }
      root.querySelectorAll('style').forEach(function (st) { recolorSheet(st.sheet); });
      if (root.nodeName === 'STYLE') recolorSheet(root.sheet);
      applyLogos(root);
    }
  }

  /* ── READABLE TEXT AFTER RECOLOURING (2026-10-21) ─────────────────────────────
     The palette map swaps each KiddieTrac colour on its own, so a pairing designed for
     contrast can be lost: KiddieTrac's lime eyebrow on navy became amber on purple
     (2.65:1). After recolouring, text that involves a brand colour - its own colour or the
     surface behind it - is checked against that surface (a gradient counts by its worst
     stop). Under 4.5:1 (3:1 for large or bold text) it takes the most readable of white,
     near-black, the primary, the accent or a deep primary. Nothing else is touched.
     KT.brandContrast.audit() lists what still fails; window.__ktNoContrastFix turns the
     fix off (for measuring). */
  function parseRgb(str) {
    const m = /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s/]+([\d.]+))?/.exec(str || '');
    return m ? { r: +m[1], g: +m[2], b: +m[3], a: m[4] == null ? 1 : +m[4] } : null;
  }
  function relLum(c) {
    const f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
  }
  function ratio(a, b) { const x = relLum(a), y = relLum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function hexRgb(h) { const c = hex2rgb(h); return c ? { r: c[0], g: c[1], b: c[2], a: 1 } : null; }
  function key(c) { return Math.round(c.r) + ',' + Math.round(c.g) + ',' + Math.round(c.b); }
  let BRAND_KEYS = null;
  function brandKeys() {
    if (BRAND_KEYS) return BRAND_KEYS;
    BRAND_KEYS = {};
    const add = function (h) { const c = hexRgb(h); if (c) BRAND_KEYS[key(c)] = 1; };
    if (COLOR_MAP) Object.keys(COLOR_MAP).forEach(function (k) { add(COLOR_MAP[k]); });
    if (THEME) { add(THEME.primary_color); add(THEME.accent_color); const h = THEME.hero || {}; add(h.from); add(h.via); add(h.to); }
    return BRAND_KEYS;
  }
  /* The colour of a linear gradient where the text actually sits (its left, middle and
     right), not its worst stop: a banner title over the dark start of a gradient reads
     even when the far end is pale. Other gradients count by all their stops. */
  function linearAt(img, box, pts) {
    const layers = img.split(/,(?![^(]*\))(?=\s*(?:repeating-)?(?:linear|radial|conic)-gradient)/);
    const lin = layers.filter(function (l) { return /linear-gradient/.test(l); }).pop();
    if (!lin) return null;
    const inner = lin.slice(lin.indexOf('(') + 1, lin.lastIndexOf(')'));
    let ang = 180;
    const am = /^\s*(-?[\d.]+)deg/.exec(inner), kw = /^\s*to\s+([a-z ]+?)\s*,/.exec(inner);
    if (am) ang = +am[1];
    else if (kw) ang = { top: 0, right: 90, bottom: 180, left: 270, 'top right': 45, 'right top': 45, 'bottom right': 135, 'right bottom': 135, 'bottom left': 225, 'left bottom': 225, 'top left': 315, 'left top': 315 }[kw[1]] || 180;
    const re = /(rgba?\([^)]+\))\s*(-?[\d.]+%)?/g; let m; const stops = [];
    while ((m = re.exec(inner))) { const c = parseRgb(m[1]); if (c) stops.push({ c: c, p: m[2] != null ? parseFloat(m[2]) / 100 : null }); }
    if (!stops.length || stops.some(function (x) { return x.c.a <= 0.5; })) return null;
    stops.forEach(function (x, i) { if (x.p == null) x.p = stops.length === 1 ? 0 : i / (stops.length - 1); });
    const a = ang * Math.PI / 180, dx = Math.sin(a), dy = -Math.cos(a);
    const L = Math.abs(box.width * dx) + Math.abs(box.height * dy) || 1;
    const cx = box.left + box.width / 2, cy = box.top + box.height / 2;
    return pts.map(function (pt) {
      const t = Math.max(0, Math.min(1, ((pt[0] - cx) * dx + (pt[1] - cy) * dy) / L + 0.5));
      let i = 0; while (i < stops.length - 1 && stops[i + 1].p < t) i++;
      const s0 = stops[i], s1 = stops[Math.min(i + 1, stops.length - 1)];
      const f = s1.p > s0.p ? Math.max(0, Math.min(1, (t - s0.p) / (s1.p - s0.p))) : 0;
      return { r: s0.c.r + (s1.c.r - s0.c.r) * f, g: s0.c.g + (s1.c.g - s0.c.g) * f, b: s0.c.b + (s1.c.b - s0.c.b) * f, a: 1, stop: f < 0.5 ? s0.c : s1.c };
    });
  }
  let BG_MEMO = null;   // element -> its background facts, for the length of one contrastPass()
  function bgOf(e) {
    let v = BG_MEMO && BG_MEMO.get(e);
    if (v) return v;
    const cs = getComputedStyle(e);
    v = { img: cs.backgroundImage, clip: cs.webkitBackgroundClip || cs.backgroundClip || '', color: cs.backgroundColor,
      size: cs.backgroundSize || '', rep: cs.backgroundRepeat || '', pos: cs.backgroundPosition || '' };
    if (BG_MEMO) BG_MEMO.set(e, v);
    return v;
  }
  /* A GRADIENT PAINTS ONLY WHERE IT IS SIZED (2026-11-11). The popup header rule paints its navy-to-teal band and
     the teal line as no-repeat background layers 57px and 3px tall on the whole dialog box. Read as covering the box,
     every word in the dialog's body measured against the header colours and was inked white on a white card (the
     proposal editor's labels vanished). With every layer no-repeat and sized in px, a word below them all sits on
     the box's own colour. Any layer that tiles or is sized otherwise still counts as covering, as before. */
  function gradientMisses(cs, box, y) {
    const sizes = cs.size.split(','), reps = cs.rep.split(','), poss = cs.pos.split(',');
    const n = cs.img.split(/,(?![^(]*\))(?=\s*(?:repeating-)?(?:linear|radial|conic)-gradient)/).length;
    for (let i = 0; i < n; i++) {
      const rp = (reps[i % reps.length] || '').trim(), sz = (sizes[i % sizes.length] || '').trim().split(/\s+/), ps = (poss[i % poss.length] || '').trim().split(/\s+/);
      if (!/^no-repeat$|^repeat-x$/.test(rp) || sz.length < 2 || !/^-?[\d.]+px$/.test(sz[1]) || !/^-?[\d.]+px$/.test(ps[1] || '')) return false;
      const top = box.top + parseFloat(ps[1]), h = parseFloat(sz[1]);
      if (y >= top && y <= top + h) return false;
    }
    return true;
  }
  // The surface behind an element: the first painted background up the tree.
  function surface(el) {
    /* The text's own extent (a block title spans the banner; its words do not). Measured only when a gradient is
       found - a flat background does not care where the words are, and measuring every line of text forced a
       layout read per element (phone speed sweep 2026-10-30). */
    let pts = null;
    const where = function () {
      if (pts) return pts;
      let r = el.getBoundingClientRect();
      try { const rg = document.createRange(); rg.selectNodeContents(el); const tr = rg.getBoundingClientRect(); if (tr.width > 0) r = tr; } catch (e) {}
      const y = r.top + r.height / 2;
      return (pts = [[r.left + 2, y], [r.left + r.width / 2, y], [r.right - 2, y]]);
    };
    for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
      const cs = bgOf(e);
      if (cs.img && /gradient/.test(cs.img) && !/text/.test(cs.clip) && !gradientMisses(cs, e.getBoundingClientRect(), where()[1][1])) {
        const at = linearAt(cs.img, e.getBoundingClientRect(), where());
        if (at) return at;
        const stops = (cs.img.match(/rgba?\([^)]+\)/g) || []).map(parseRgb).filter(function (c) { return c && c.a > 0.5; });
        if (stops.length) return stops;
      }
      const bg = parseRgb(cs.color);
      if (bg && bg.a > 0.5) return [bg];
    }
    return [{ r: 255, g: 255, b: 255, a: 1 }];
  }
  // Words of its own (an emoji paints in its own colours, so its text colour does not matter).
  const WORD_RE = /[A-Za-z0-9À-ɏऀ-ॿ]/;
  function ownText(el) {
    for (let n = el.firstChild; n; n = n.nextSibling) { if (n.nodeType === 3 && WORD_RE.test(n.nodeValue)) return true; }
    return false;
  }
  function check(el) {
    /* BANNERS ARE JUDGED ONCE, AS A WHOLE (2026-10-23, Anthony: "the hero banners having a black
       colour for the font and its hard to read"). Brand::heroText picks light or dark text for
       the whole banner (applyHero). Judged line by line here, a sub-title spanning a purple →
       amber banner measured the amber end, found white too faint there and switched the line
       to navy — unreadable on the purple where it starts. Banners keep their own rule. */
    if (el.closest && el.closest(HERO_SEL)) return null;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || !el.offsetWidth) return null;
    if (/text/.test(cs.webkitBackgroundClip || '') || /transparent|rgba\(0, 0, 0, 0\)/.test(cs.webkitTextFillColor || '')) return null;   // gradient text
    const fg = parseRgb(cs.color); if (!fg || fg.a < 0.4) return null;
    const bgs = surface(el), keys = brandKeys();
    if (!keys[key(fg)] && !bgs.some(function (b) { return keys[key(b.stop || b)]; })) return null;   // no brand colour involved
    const size = parseFloat(cs.fontSize) || 14, bold = (+cs.fontWeight || 400) >= 700;
    const need = (size >= 24 || (bold && size >= 18.66)) ? 3 : 4.5;
    const worst = Math.min.apply(null, bgs.map(function (b) { return ratio(fg, b); }));
    return { el: el, fg: fg, bgs: bgs, need: need, worst: worst };
  }
  function bestInk(bgs) {
    const cands = ['#FFFFFF', '#0F172A', THEME && THEME.primary_color, THEME && THEME.accent_color, THEME && mix(THEME.primary_color, '#000000', 0.45)]
      .map(hexRgb).filter(Boolean);
    let best = null, bestR = 0;
    cands.forEach(function (c) { const r = Math.min.apply(null, bgs.map(function (b) { return ratio(c, b); })); if (r > bestR) { bestR = r; best = c; } });
    return best ? 'rgb(' + key(best) + ')' : null;
  }
  function contrastPass(root) {
    if (!THEME || window.__ktNoContrastFix) return;
    const scope = root && root.querySelectorAll ? root : document.body;
    if (!scope) return;
    /* PERF (2026-10-28): three passes - un-ink, measure, ink. Un-inking an element and measuring it straight away
       made every text element on the page pay a full style recalculation (0.74 s of a phone navigation). */
    const els = [scope].concat(Array.prototype.slice.call(scope.querySelectorAll('*')));
    const todo = [];
    for (let i = 0; i < els.length; i++) {
      const el = els[i];
      if (!ownText(el) || /^(SCRIPT|STYLE|NOSCRIPT|OPTION|TEXTAREA|INPUT)$/.test(el.tagName)) continue;
      /* PUT BACK THE SCREEN'S OWN COLOUR (2026-11-06, Anthony: Settings > Profile "Save profile" was dark grey and
         unreadable). Inking overwrote a button's inline colour (white on its gradient) and un-inking then removed the
         colour altogether, so the next pass found no colour of its own - a disabled button fell back to the browser's
         30% grey. The colour the screen set is kept on the element and restored before it is judged again. */
      if (el.dataset && el.dataset.ktInk) {
        const was = el.__ktInkWas;
        if (was && was[0]) el.style.setProperty('color', was[0], was[1]); else el.style.removeProperty('color');
        delete el.dataset.ktInk; el.__ktInkWas = null;
      }
      todo.push(el);
    }
    const inks = [];
    BG_MEMO = new Map();
    try {
      for (let i = 0; i < todo.length; i++) {
        const r = check(todo[i]);
        if (r && r.worst < r.need) { const ink = bestInk(r.bgs); if (ink) inks.push([todo[i], ink]); }
      }
    } finally { BG_MEMO = null; }
    for (let i = 0; i < inks.length; i++) {
      const e = inks[i][0];
      e.__ktInkWas = [e.style.getPropertyValue('color'), e.style.getPropertyPriority('color')];
      e.style.setProperty('color', inks[i][1], 'important'); e.dataset.ktInk = '1';
    }
  }
  // A whole-page pass after a theme change or a navigation; after other changes, only what changed.
  let contrastTimer = null, contrastFull = false;
  const contrastRoots = new Set();
  function scheduleContrast(delay, roots) {
    if (roots) roots.forEach(function (r) { if (r && r.nodeType === 1) contrastRoots.add(r); }); else contrastFull = true;
    // A throttle, not a debounce: a screen that changes every second (a clock) must still get its pass.
    if (contrastTimer) return;
    contrastTimer = setTimeout(function () {
      contrastTimer = null;
      const full = contrastFull, list = Array.from(contrastRoots);
      contrastFull = false; contrastRoots.clear();
      try { if (full) contrastPass(document.body); else list.forEach(function (r) { if (r.isConnected) contrastPass(r); }); } catch (e) {}
    }, delay || 250);
  }
  /* Screens finish in stages (data arrives, a banner takes its colours from a class added
     later), which the child-list observer does not see. A whole-page look as a screen
     settles: just after it opens, and twice more while it fills in. */
  function screenRoots() {
    const out = [];
    const m = document.getElementById('appMain'); if (m) out.push(m);
    document.querySelectorAll('.kt-modal, [role="dialog"]').forEach(function (d) { out.push(d); });
    return out.length ? out : null;
  }
  function contrastSettle() {
    scheduleContrast(600);
    setTimeout(function () { scheduleContrast(50, screenRoots()); }, 2500);
    setTimeout(function () { scheduleContrast(50, screenRoots()); }, 6000);
  }
  window.KT = window.KT || {};
  window.KT.brandContrast = {
    audit: function (min) {
      const out = [];
      if (!THEME || !document.body) return out;
      document.body.querySelectorAll('*').forEach(function (el) {
        if (!ownText(el)) return;
        const r = check(el);
        if (r && r.worst < (min || 3)) out.push({ text: el.textContent.trim().slice(0, 40), ratio: Math.round(r.worst * 100) / 100, fixed: !!(el.dataset && el.dataset.ktInk) });
      });
      return out;
    },
    fix: function () { contrastPass(document.body); },
  };

  function startObserver() {
    if (observer || !THEME || !window.MutationObserver) return;
    let queue = [], scheduled = false;
    const flush = function () {
      scheduled = false;
      const batch = queue; queue = [];
      batch.forEach(function (n) { if (n.isConnected) sweep(n.nodeType === 3 ? n.parentNode : n); });
      scheduleContrast(250, batch.map(function (n) { return n.nodeType === 3 ? n.parentNode : n; }));
      if (document.title && /Kiddie ?[Tt]rac/.test(document.title)) document.title = renameStr(document.title);
    };
    // Overlays and screens are appended under <body>; one observer, batched per frame.
    observer = new MutationObserver(function (muts) {
      for (const m of muts) {
        if (m.type === 'characterData') { queue.push(m.target); continue; }
        m.addedNodes.forEach(function (n) { if (n.nodeType === 1 || n.nodeType === 3) queue.push(n); });
      }
      if (queue.length && !scheduled) { scheduled = true; (window.requestAnimationFrame || setTimeout)(flush); }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true, characterData: true });
    const t = document.querySelector('title');
    if (t) observer.observe(t, { childList: true, characterData: true, subtree: true });
    window.addEventListener('hashchange', function () {
      setTimeout(function () { sweep(document.body); }, 60);
      contrastSettle();
    });
  }

  /* ── logos, icon, manifest ────────────────────────────────────────── */
  function wordmark() {
    if (!THEME) return '';
    const u = absUrl(THEME.logo_wordmark_url);
    return u && u !== KT_WORDMARK ? u : '';
  }
  function applyLogos(root) {
    const scope = root && root.querySelectorAll ? root : document;
    const logo = wordmark();
    document.documentElement.classList.toggle('kt-wl', !!THEME);
    const els = [];
    if (scope.matches && scope.matches(LOGO_SEL)) els.push(scope);
    scope.querySelectorAll(LOGO_SEL).forEach(function (el) { els.push(el); });
    els.forEach(function (el) {
      if (el.tagName !== 'IMG') return;
      if (!el.hasAttribute('data-kt-default-src')) {
        el.setAttribute('data-kt-default-src', el.getAttribute('src') || '');
        el.setAttribute('data-kt-default-alt', el.getAttribute('alt') || '');
      }
      const txt = el.nextElementSibling && el.nextElementSibling.classList.contains('kt-wl-textlogo') ? el.nextElementSibling : null;
      if (THEME && logo) {
        if (el.getAttribute('src') !== logo) { el.onerror = function () { el.onerror = null; textLogo(el); }; el.src = logo; }
        el.alt = brandName(); el.setAttribute('data-kt-wl', '1'); el.style.display = '';
        if (txt) txt.remove();
      } else if (THEME) {
        textLogo(el);                       // white-label with no logo: the name, never ours
      } else if (el.hasAttribute('data-kt-wl')) {
        restore(el);
      }
    });
  }
  function textLogo(el) {
    el.setAttribute('data-kt-wl', '1');
    el.style.display = 'none';
    let t = el.nextElementSibling;
    if (!t || !t.classList.contains('kt-wl-textlogo')) {
      t = document.createElement('span');
      t.className = 'kt-wl-textlogo';
      el.parentNode && el.parentNode.insertBefore(t, el.nextSibling);
    }
    t.textContent = brandName();
    /* ONE SIZE (2026-11-02, Anthony's iPhone recording): the header's "Little Steps" drew at 15px on the
       first paint and jumped to 26px on the next screen. The size came from the logo slot's height, but on
       every later call the img had already been hidden (display:none above), so it measured 0 and fell back
       to the image's natural height (120 -> 26px). Measure the slot itself, once, and keep that size. */
    let fs = +el.getAttribute('data-kt-txt-fs') || 0;
    if (!fs) {
      let h = 0;
      try { el.style.display = ''; h = el.getBoundingClientRect().height; } catch (e) {}
      el.style.display = 'none';
      fs = Math.round(Math.max(15, Math.min(26, (h || el.height || 40) * 0.34)));
      if (h) el.setAttribute('data-kt-txt-fs', String(fs));
    }
    t.style.cssText = 'display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;'
      + 'font-weight:900;letter-spacing:-.3px;line-height:1.15;color:' + (THEME.primary_color || '#1F6080')
      + ';font-size:' + fs + 'px;';
  }
  function restore(el) {
    el.src = el.getAttribute('data-kt-default-src') || el.src;
    el.alt = el.getAttribute('data-kt-default-alt') || '';
    el.style.display = '';
    el.removeAttribute('data-kt-wl');
    const t = el.nextElementSibling;
    if (t && t.classList.contains('kt-wl-textlogo')) t.remove();
  }

  function letterIcon(size) {
    try {
      const c = document.createElement('canvas'); c.width = c.height = size;
      const g = c.getContext('2d');
      g.fillStyle = THEME.primary_color || '#1F6080';
      const r = size * 0.22; g.beginPath(); g.moveTo(r, 0); g.arcTo(size, 0, size, size, r); g.arcTo(size, size, 0, size, r); g.arcTo(0, size, 0, 0, r); g.arcTo(0, 0, size, 0, r); g.fill();
      g.fillStyle = '#fff'; g.font = '900 ' + Math.round(size * 0.58) + 'px system-ui, sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
      g.fillText((brandName().trim()[0] || '•').toUpperCase(), size / 2, size / 2 + size * 0.04);
      return c.toDataURL('image/png');
    } catch (e) { return ''; }
  }
  let manifestUrl = null;
  function applyHead() {
    if (!THEME) return;
    const fav = absUrl(THEME.favicon_url);
    const icon = fav && fav !== KT_FAVICON ? fav : letterIcon(64);
    document.querySelectorAll("link[rel~='icon'], link[rel='shortcut icon']").forEach(function (l) { l.href = icon; l.removeAttribute('type'); });
    if (!document.querySelector("link[rel~='icon']")) { const l = document.createElement('link'); l.rel = 'icon'; l.href = icon; document.head.appendChild(l); }
    const sq = absUrl(THEME.logo_square_url);
    const touch = sq && !/app\.kiddietrac\.com\/icon-512/.test(sq) ? sq : letterIcon(180);
    document.querySelectorAll("link[rel='apple-touch-icon']").forEach(function (l) { l.href = touch; });
    let meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', THEME.primary_color);
    if (document.title && /Kiddie ?[Tt]rac/.test(document.title)) document.title = renameStr(document.title);
    // Installing to the home screen uses the brand's name and icon.
    try {
      const m = {
        name: brandName(), short_name: brandName().slice(0, 12), display: 'standalone',
        start_url: location.origin + '/dashboard.html', scope: location.origin + '/',
        theme_color: THEME.primary_color, background_color: THEME.splash_bg || '#ffffff',
        icons: [{ src: !/app\.kiddietrac\.com\/icon-512/.test(sq) ? sq : letterIcon(512), sizes: '512x512', type: 'image/png', purpose: 'any' }],
      };
      if (manifestUrl) URL.revokeObjectURL(manifestUrl);
      manifestUrl = URL.createObjectURL(new Blob([JSON.stringify(m)], { type: 'application/manifest+json' }));
      let l = document.querySelector('link[rel="manifest"]');
      if (!l) { l = document.createElement('link'); l.rel = 'manifest'; document.head.appendChild(l); }
      l.href = manifestUrl;
    } catch (e) {}
  }

  /* THE BRAND'S PAGE BANNER (2026-10-21). Every banner - a screen's own .kt-hero /
     .kt-page-hero, the shell's .kt-hero-auto, the older .page-header-v17 - draws the brand
     gradient (theme.hero, worked out by App\Support\Brand::hero) instead of KiddieTrac's
     per-screen palette. The palette tokens are pointed at it too, so anything drawing
     var(--kt-hero-grad*) follows. Too-pale colours switch banner text to dark. */
  const HERO_SEL = '.kt-hero,.kt-page-hero,.kt-hero-auto,.page-header-v17,[data-kt-hero]';
  function applyHero(h) {
    let el = document.getElementById('kt-brand-hero');
    if (!h || !h.css) { if (el) el.remove(); return; }
    if (!el) { el = document.createElement('style'); el.id = 'kt-brand-hero'; (document.head || document.documentElement).appendChild(el); }
    const toks = ['', '-warm', '-a', '-b', '-c', '-d', '-e', '-f', '-g', '-h'].map(function (x) { return '--kt-hero-grad' + x + ':var(--kt-brand-hero) !important;'; }).join('');
    let css = ':root{--kt-brand-hero:' + h.css + ' !important;' + toks + '}'
      + HERO_SEL + '{background:var(--kt-brand-hero) !important;background-size:200% 200% !important;}';
    if (h.text === 'dark') {
      const kids = HERO_SEL.split(',').map(function (s) { return s + ',' + s + ' :is(h1,h2,h3,h4,p,small,span,div,.kt-hero-emoji)'; }).join(',');
      css += kids + '{color:#0F172A !important;text-shadow:none !important;}'
        + HERO_SEL.split(',').map(function (s) { return s + ' .kt-btn'; }).join(',') + '{color:#0F172A !important;border-color:rgba(15,23,42,.3) !important;}';
    } else {
      // White on the brand gradient: a soft shadow keeps it readable over the lighter end.
      const kidsL = HERO_SEL.split(',').map(function (s) { return s + ' :is(h1,h2,h3,h4,p,small,span,div)'; }).join(',');
      css += kidsL + '{text-shadow:0 1px 2px rgba(15,23,42,.35);}';
    }
    el.textContent = css;
  }

  function applyTheme(t) {
    THEME = t && t.white_label ? t : null;
    COLOR_MAP = THEME ? buildColorMap(THEME) : null;
    RGB_MAP = null;
    const root = document.documentElement;
    if (THEME) {
      root.style.setProperty('--brand-blue', THEME.primary_color);
      root.style.setProperty('--brand-green', THEME.accent_color);
      root.style.setProperty('--kt-blue', THEME.primary_color);
      root.style.setProperty('--kt-brand-primary', THEME.primary_color);
      root.style.setProperty('--kt-brand-accent', THEME.accent_color);
      root.style.setProperty('--kt-blue-50', mix(THEME.primary_color, '#ffffff', 0.9));
      applyTint(THEME);
      applyHero(THEME.hero || null);
      BRAND_KEYS = null;
      contrastSettle();
      root.style.setProperty('--kt-wl-spin', THEME.accent_color);
      for (let i = 0; i < document.styleSheets.length; i++) recolorSheet(document.styleSheets[i]);
      // Stylesheets that finish loading later (link elements) get the same pass.
      document.querySelectorAll('link[rel="stylesheet"]').forEach(function (l) { if (!l.sheet) l.addEventListener('load', function () { recolorSheet(l.sheet); }, { once: true }); });
      applyHead();
      if (document.body) { sweep(document.body); startObserver(); }
      document.querySelectorAll('.kt-powered-by, [data-powered-by]').forEach(function (el) { el.style.display = 'none'; });
    } else {
      applyTint(null);
      applyHero(null);
      applyLogos(document);
    }
    window.KT.brand = THEME;
    window.KT.brandTint = brandTint;
    try { window.dispatchEvent(new CustomEvent('kt:brand', { detail: THEME })); } catch (e) {}
    // A reseller's own sign-in page (index.html, ?tenant=) wears its scheme; the plain one stays navy.
    try {
      if (window.KT_applySigninScheme && !sessionStorage.getItem('kt_token') && !localStorage.getItem('kt_token')) {
        window.KT_applySigninScheme(THEME && activeAgencyHint().slug ? THEME : null);
      }
    } catch (e) {}
  }

  // Legacy fields (colours, subtitle) for sessions that are not white-label.
  function applyLegacy(b) {
    if (!b) return;
    const subEls = document.querySelectorAll('.login-subtitle, [data-brand-subtitle]');
    if (b.login_subtitle) subEls.forEach(function (el) { el.textContent = b.login_subtitle; });
    document.querySelectorAll('.brand-name, [data-brand-name]').forEach(function (el) { el.textContent = brandName(); });
  }

  /* A WHITE-LABEL AGENCY'S OWN SIGN-IN PAGE (2026-10-28, Anthony: "completely white labelled including a new landing
     page for the login"). index.html's showcase panel is KiddieTrac's marketing (Canada-first, CWELCC, CCEYA, AI), which
     must not appear under someone else's name. On ?tenant=<agency> it is rewritten from settings.branding.login_page
     ({badge, headline: [lines], highlight, lede, features: [{icon, title, text}], trust: [..]}) or, without one, plain
     words that claim nothing regional. The subtitle is the agency's login_subtitle, and "Contact us" mails its support
     address. Text only (textContent), so nothing an admin types can become markup. */
  function applySigninCopy(data, hint) {
    const side = document.querySelector('.ktl-brand-side');
    if (!side || !data || !hint.slug || !themeOf(data, hint)) return;
    try { if (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token')) return; } catch (e) {}
    const b = data.branding || {};
    const us = String(data.country || '').toUpperCase() === 'US';
    const name = (THEME && THEME.name) || b.product_name || data.agency_name || '';
    const lp = (b.login_page && typeof b.login_page === 'object') ? b.login_page : {};
    const centre = us ? 'center' : 'centre';
    const copy = {
      badge: lp.badge || ('✦ ' + name.toUpperCase()),
      headline: Array.isArray(lp.headline) && lp.headline.length ? lp.headline : ['Every moment,', 'every milestone —'],
      highlight: lp.highlight || 'shared with you.',
      lede: lp.lede || ('From drop-off to pick-up, ' + name + ' keeps you close to your child\u2019s day — photos, meals, naps and notes from their teachers.'),
      features: Array.isArray(lp.features) && lp.features.length ? lp.features : [
        { icon: '📸', title: 'Photos & daily updates', text: 'See what your child is doing, eating and learning — as it happens.' },
        { icon: '💬', title: 'Message the classroom', text: 'Reach your child\u2019s teachers anytime, right from your phone.' },
        { icon: '💳', title: 'Simple payments', text: 'Pay online, see your statement and download receipts anytime.' },
      ],
      trust: Array.isArray(lp.trust) ? lp.trust : ['Licensed child care', 'Secure sign-in', 'Private to your family'],
    };
    const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
    const badge = side.querySelector('.ktl-badge'); if (badge) badge.textContent = copy.badge;
    const h2 = side.querySelector('.ktl-headline');
    if (h2) {
      h2.textContent = '';
      copy.headline.forEach(function (line) { h2.appendChild(document.createTextNode(String(line))); h2.appendChild(document.createElement('br')); });
      h2.appendChild(el('span', 'ktl-hl', copy.highlight));
    }
    const lede = side.querySelector('.ktl-lede'); if (lede) lede.textContent = copy.lede;
    const feats = side.querySelector('.ktl-features');
    if (feats) {
      feats.textContent = '';
      copy.features.slice(0, 4).forEach(function (f) {
        const row = el('div', 'ktl-feat'); row.appendChild(el('span', 'ic', f.icon || '✓'));
        const box = el('div'); box.appendChild(el('h3', null, f.title || '')); box.appendChild(el('p', null, f.text || ''));
        row.appendChild(box); feats.appendChild(row);
      });
    }
    const trust = side.querySelector('.ktl-trust');
    if (trust) { trust.textContent = ''; copy.trust.slice(0, 4).forEach(function (t) { trust.appendChild(el('span', null, t)); }); trust.style.display = copy.trust.length ? '' : 'none'; }
    if (b.login_subtitle) document.querySelectorAll('.ktl-sub.auth-sub').forEach(function (p) { p.textContent = b.login_subtitle; });
    const foot = document.querySelector('.ktl-foot');
    if (foot) {
      foot.textContent = 'No account yet? Your child care ' + centre + ' will send you an invitation email. ';
      if (b.support_email) {
        const a = el('a', null, 'Contact us'); a.href = 'mailto:' + b.support_email; foot.appendChild(a);
        foot.appendChild(document.createTextNode(' if you need help.'));
      }
    }
    try { document.title = 'Sign in · ' + name; } catch (e) {}
  }

  // Which agency should the loader brand for? Session agency -> user record -> ?tenant=.
  function activeAgencyHint() {
    var hint = {};
    var land = landingAgencyId();
    if (land) { hint.agency_id = land; return hint; }
    var qs = new URLSearchParams(window.location.search);
    var slug = qs.get('tenant') || qs.get('slug');
    try {
      if (slug && /^[a-z0-9-]{1,80}$/i.test(slug)) sessionStorage.setItem(TENANT_KEY, slug);
      if (!slug) slug = sessionStorage.getItem(TENANT_KEY);
    } catch (e) {}
    if (slug) hint.slug = slug;
    return hint;
  }
  // Only ever brand from an answer about THIS session (or this app build's tenant).
  function mine(entry, hint) {
    if (!entry) return false;
    if (hint.agency_id) return String(entry.agency_id) === String(hint.agency_id);
    if (hint.slug) return entry.agency_slug === hint.slug || entry.reseller_slug === hint.slug;
    return false;
  }
  function themeOf(entry, hint) { return entry && entry.theme && mine(entry, hint) ? entry.theme : null; }
  function readCache() { try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { return null; } }
  /* ONE BRAND PER IDENTITY (2026-10-21, fix 3). The shared kt_branding entry is only ever
     used when it matches this session; the unlock screen and the first frames after a
     sign-in read the entry for the ACCOUNT's own agency, kt_brand_a<id>, so a previous
     white-label session on this device can never dress the next person. */
  function agencyCacheKey(id) { return 'kt_brand_a' + String(id); }
  /* The agency the portal opens in: the active one, else where /auth/me says it will land
     (landing_agency_id: a super admin's default agency, a Reseller's first), else the
     account's own. The splash reads the cache under this same id (2026-10-21). */
  function landingAgencyId() {
    try {
      // Signed out, there is no agency to brand for - only this app build's tenant (?tenant=).
      // A tab keeps kt_active_agency_id after a sign-out, which dressed the sign-in page in
      // the last white-label agency's brand.
      if (!sessionStorage.getItem('kt_token') && !localStorage.getItem('kt_token')) return null;
      var aid = sessionStorage.getItem('kt_active_agency_id');
      if (aid && aid !== 'all') return String(aid);
      var u = JSON.parse(sessionStorage.getItem('kt_user') || 'null');
      var l = u && (u.landing_agency_id || u.agency_id);
      return l ? String(l) : null;
    } catch (e) { return null; }
  }
  window.KT.landingAgencyId = landingAgencyId;
  function writeCaches(data) {
    const entry = {
      branding: data.branding, theme: data.theme || null, agency_id: data.agency_id,
      agency_slug: data.agency_slug || null, reseller_slug: data.reseller_slug || null,
      expires: Date.now() + CACHE_TTL_MS,
    };
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(entry)); } catch (e) {}
    if (data.agency_id) {
      try { localStorage.setItem(agencyCacheKey(data.agency_id), JSON.stringify({ theme: data.theme || null, at: Date.now() })); } catch (e) {}
    }
  }
  function themeForAgency(id) {
    if (!id) return null;
    try {
      const e = JSON.parse(localStorage.getItem(agencyCacheKey(id)) || 'null');
      return e && e.theme && e.theme.white_label ? e.theme : null;
    } catch (e) { return null; }
  }
  // Signed out and nobody signed in: the shared entry describes nobody any more.
  try {
    if (!sessionStorage.getItem('kt_token') && localStorage.getItem('kt_signed_out') === '1') localStorage.removeItem(STORAGE_KEY);
    localStorage.removeItem(TENANT_KEY);   // the old, device-wide tenant (before fix 3)
  } catch (e) {}

  async function load() {
    try { return await loadBrand(); }
    finally { document.documentElement.classList.remove('kt-brand-pending'); }   // the splash's brand-less hold (dashboard.src.html)
  }
  async function loadBrand() {
    const hint = activeAgencyHint();
    try {
      const cached = readCache();
      if (cached) { applyTheme(themeOf(cached, hint)); applyLegacy(cached.branding); applySigninCopy(cached, hint); }
    } catch (e) {}
    try {
      const qs = new URLSearchParams(hint).toString();
      /* By agency number the server only names the agency to a member of it (2026-11-05), so the
         signed-in session's token goes with it; a slug lookup is public and needs none. */
      let tk = null;
      try { tk = hint.agency_id ? (sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token')) : null; } catch (e) {}
      const res = await fetch(apiBase() + '/branding' + (qs ? '?' + qs : ''),
        tk ? { headers: { Authorization: 'Bearer ' + tk } } : undefined);
      if (!res.ok) return;
      const data = await res.json();
      applyTheme(themeOf(data, hint));
      applyLegacy(data.branding);
      applySigninCopy(data, hint);
      writeCaches(data);
      // The Android app keeps the brand for its native pieces (splash, notifications).
      try {
        const P = window.Capacitor && window.Capacitor.Plugins;
        if (P && P.KtBio && P.KtBio.setBrand) {
          P.KtBio.setBrand(THEME ? { name: THEME.name, primary: THEME.primary_color, accent: THEME.accent_color, splash_bg: THEME.splash_bg,
            splash_logo: absUrl(THEME.splash_logo_url), white_label: true } : { white_label: false });
        }
      } catch (e) {}
    } catch (e) {
      console.warn('Branding load failed', e);
    }
  }

  window.KT = window.KT || {};
  window.KT.applyBranding = function (b) { applyLegacy(b); };
  window.KT.reloadBranding = load;
  window.KT.brandName = brandName;
  window.KT.brandRename = function (s) { return THEME ? renameStr(String(s)) : String(s); };
  // For chrome built after load (the unlock screen): the white-label logo, or ''.
  window.KT.brandLogo = function (agencyId) {
    var t = agencyId ? themeForAgency(agencyId) : themeOf(readCache(), activeAgencyHint());
    var u = t && t.white_label ? absUrl(t.logo_wordmark_url) : '';
    return u && u !== KT_WORDMARK ? u : '';
  };
  // The white-label theme of one account's agency (unlock screen), or null for KiddieTrac.
  window.KT.brandFor = themeForAgency;
  /* FIX 2: called by the sign-in fade (KT_portalFade) once the new session exists, so the
     dashboard's first frames already wear the person's OWN brand - which is KiddieTrac
     when their agency is not white-label, even if they arrived through a reseller link. */
  window.KT.primeBrand = async function () {
    try {
      const a = landingAgencyId();
      const tok = sessionStorage.getItem('kt_token');
      const res = await fetch(apiBase() + '/branding' + (a ? '?agency_id=' + encodeURIComponent(a) : ''),
        tok ? { headers: { Authorization: 'Bearer ' + tok } } : undefined);
      if (!res.ok) return;
      writeCaches(await res.json());
    } catch (e) {}
  };

  window.addEventListener('kt:agency-switched', function () {
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    load();
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
  else load();
})(window);
