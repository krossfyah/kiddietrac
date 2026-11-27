/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p68 — Contextual help "?" buttons + THE help drawer
   • Add `data-kt-help="article-slug"` to any element
   • A small "?" pill appears at top-right; clicking opens article in a drawer
   • Survives screen re-renders via MutationObserver
   • KT.openHelpDrawer(slug | [slugs], {related}) is the one help reader: the "?" on every screen
     (kt-page-help.js), the HR starter-path "?" (kt-hr-quick.js) and these pills all open it.

   THE READER (2026-11-27, Anthony: "the help in each section the pop up is great but hard to read can you
   present this better - full sweep"). It was a 520px panel of 14px text whose article styles (callouts, FAQs,
   steps) lived in the Help centre's stylesheet, injected only once Help had been opened, so most readers got
   unstyled markdown under the title printed twice. Now:
     - a wide side panel on a computer (min(760px, 94vw)), the full screen on a phone; a side panel rather than a
       centred popup because "Show me" and "Open" walk the reader to the screen behind it, and the steps are
       easier to follow with that screen still in view;
     - the house header (navy to teal + teal line) holds the article's title ONCE, its category above it, the ✕
       inside it; the body is the one scroll region;
     - .kt-help-read (below) is the reading style, shared with the Help centre article view: 15.5px / 1.65, lines
       of at most ~68 characters, H2 sections with a divider, numbered steps as cards with 1 2 3 badges, **Button
       names** as UI chips, 💡 / ℹ️ / ⚠️ callouts, FAQs (??? Q | A) as rows that open, "On this page" links for a
       long article, and the other guides for the screen as buttons;
     - light and dark (html.kt-dark, data-theme=dark, or the device setting); a white-label tenant tints the
       accents only (--kt-brand-primary), never the reading colours.
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';
  function getApi() { return (window.KT && window.KT.Api) || null; }
  function t(s) { try { return (window.KT && KT.i18n && KT.i18n.t) ? KT.i18n.t(s) : s; } catch (e) { return s; } }

  function attach(el) {
    if (el._ktHelpAttached) return;
    el._ktHelpAttached = true;

    // Make sure parent has position:relative
    const cs = getComputedStyle(el);
    if (cs.position === 'static') el.style.position = 'relative';

    const slug = el.getAttribute('data-kt-help');
    const btn = document.createElement('button');
    btn.className = 'kt-ctx-help';
    btn.title = 'Help for this section';
    btn.innerHTML = '?';
    btn.style.cssText = `
      position: absolute; top: 12px; right: 12px;
      width: 24px; height: 24px; border-radius: 50%;
      background: rgba(31, 96, 128, 0.10); color: #1F6080;
      border: none; font-weight: 800; font-size: 13px;
      cursor: pointer; z-index: 5; transition: all 0.15s;
    `;
    btn.addEventListener('mouseenter', () => {
      btn.style.background = '#1F6080';
      btn.style.color = 'white';
      btn.style.transform = 'scale(1.15)';
    });
    btn.addEventListener('mouseleave', () => {
      btn.style.background = 'rgba(31, 96, 128, 0.10)';
      btn.style.color = '#1F6080';
      btn.style.transform = 'scale(1)';
    });
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      openDrawer(slug);
    });
    el.appendChild(btn);
  }

  function scan(root) {
    (root || document).querySelectorAll('[data-kt-help]').forEach(attach);
  }

  /* ── the reading style (drawer + Help centre article) ─────────────────────────────── */
  const READ_CSS_ID = 'kt-help-read-css';
  function readCss() {
    if (document.getElementById(READ_CSS_ID)) return;
    const L = '--hr-ink:#1F2937;--hr-head:#0F172A;--hr-mute:#64748B;--hr-line:#E2E8F0;--hr-bg:#FFFFFF;--hr-soft:#F8FAFC;--hr-chip:#F1F5F9;--hr-chipb:#CBD5E1;'
      + '--hr-tip:#F0FDF4;--hr-tipb:#16A34A;--hr-note:#EFF6FF;--hr-noteb:#2563EB;--hr-warn:#FFFBEB;--hr-warnb:#D97706;';
    const D = '--hr-ink:#E2E8F0;--hr-head:#F8FAFC;--hr-mute:#94A3B8;--hr-line:#1E293B;--hr-bg:#0B1220;--hr-soft:#111A2E;--hr-chip:#1E293B;--hr-chipb:#334155;'
      + '--hr-tip:#052E1A;--hr-tipb:#22C55E;--hr-note:#0B1E3F;--hr-noteb:#60A5FA;--hr-warn:#2A1E05;--hr-warnb:#F59E0B;';
    const A = 'var(--kt-brand-primary,#0E7490)';
    const R = '.kt-help-read';
    const M = R + ' .kt-help-markdown';
    const s = document.createElement('style');
    s.id = READ_CSS_ID;
    s.textContent = ''
      + R + ',#kt-help-drawer{' + L + '}'
      + 'html.kt-dark ' + R + ',html[data-theme="dark"] ' + R + ',html.kt-dark #kt-help-drawer,html[data-theme="dark"] #kt-help-drawer{' + D + '}'
      + '@media (prefers-color-scheme: dark){html:not([data-theme="light"]) #kt-help-drawer,html:not([data-theme="light"]) #kt-help-drawer ' + R + '{' + D + '}}'
      + M + '{font-size:15.5px;line-height:1.65;color:var(--hr-ink);max-width:68ch;overflow-wrap:anywhere;}'
      + M + ' > :first-child{margin-top:0;}'
      + M + ' p{margin:0 0 14px;}'
      + M + ' h1{font-size:22px;line-height:1.25;margin:0 0 14px;color:var(--hr-head);}'
      + M + ' h2{font-size:18px;line-height:1.3;font-weight:800;color:var(--hr-head);margin:30px 0 10px;padding:18px 0 0;border:0;border-top:1px solid var(--hr-line);}'
      + M + ' > h2:first-child{border-top:0;padding-top:0;}'
      + M + ' h3{font-size:15.5px;line-height:1.35;font-weight:800;color:var(--hr-head);margin:22px 0 6px;}'
      + M + ' ul{padding-left:22px;margin:0 0 14px;}'
      + M + ' li{margin:5px 0;}'
      + M + ' li::marker{color:' + A + ';}'
      // Numbered steps: cards with a numbered badge.
      + M + ' ol{list-style:none;counter-reset:kthstep;padding:0;margin:4px 0 18px;}'
      + M + ' ol > li{counter-increment:kthstep;position:relative;margin:8px 0;padding:11px 14px 11px 50px;border:1px solid var(--hr-line);border-radius:12px;background:var(--hr-soft);}'
      + M + ' ol > li::before{content:counter(kthstep);position:absolute;left:13px;top:10px;width:26px;height:26px;border-radius:50%;background:' + A + ';color:#fff;font-weight:800;font-size:13px;line-height:26px;text-align:center;}'
      // Button, tab and menu names (**Save**) as UI chips; a callout's lead-in stays bold text.
      + M + ' :is(p,li,td,div) > strong{display:inline;padding:1px 7px;margin:0 1px;border:1px solid var(--hr-chipb);border-radius:6px;background:var(--hr-chip);color:var(--hr-head);font-weight:700;font-size:.93em;-webkit-box-decoration-break:clone;box-decoration-break:clone;}'
      + M + ' blockquote > strong:first-child,' + M + ' summary strong{padding:0;margin:0;border:0;background:none;font-size:1em;}'
      + M + ' code{background:var(--hr-chip);color:var(--hr-head);padding:1px 6px;border-radius:5px;font-size:.88em;font-family:Menlo,Consolas,monospace;}'
      + M + ' a{color:' + A + ';text-decoration:underline;text-underline-offset:2px;}'
      + M + ' a.kt-help-wiki{text-decoration:none;font-weight:700;background:none;padding:0;border-bottom:1px dashed currentColor;}'
      // Callouts.
      + M + ' blockquote{margin:14px 0;padding:11px 14px;border-left:4px solid var(--hr-line);border-radius:0 10px 10px 0;background:var(--hr-soft);color:var(--hr-ink);font-style:normal;}'
      + M + ' blockquote.kt-help-callout{border-radius:10px;}'
      + M + ' .kt-help-callout-tip{background:var(--hr-tip);border-left-color:var(--hr-tipb);}'
      + M + ' .kt-help-callout-note{background:var(--hr-note);border-left-color:var(--hr-noteb);}'
      + M + ' .kt-help-callout-important{background:var(--hr-warn);border-left-color:var(--hr-warnb);}'
      // FAQs.
      + M + ' details.kt-help-qa{border:1px solid var(--hr-line);border-radius:10px;margin:8px 0;background:var(--hr-bg);}'
      + M + ' details.kt-help-qa summary{cursor:pointer;list-style:none;padding:12px 40px 12px 14px;font-weight:700;color:var(--hr-head);position:relative;}'
      + M + ' details.kt-help-qa summary::-webkit-details-marker{display:none;}'
      + M + ' details.kt-help-qa summary::before{content:none;}'
      + M + ' details.kt-help-qa summary::after{content:"";position:absolute;right:16px;top:50%;width:8px;height:8px;margin-top:-6px;border-right:2px solid ' + A + ';border-bottom:2px solid ' + A + ';transform:rotate(45deg);transition:transform .15s;}'
      + M + ' details.kt-help-qa[open] summary::after{transform:rotate(-135deg);margin-top:-2px;}'
      + M + ' .kt-help-qa-a{padding:0 14px 12px;color:var(--hr-ink);}'
      // Tables scroll inside themselves, never the page.
      + M + ' table{display:block;max-width:100%;overflow-x:auto;border-collapse:collapse;margin:14px 0;font-size:14px;}'
      + M + ' th{background:var(--hr-soft);color:var(--hr-head);text-align:left;padding:9px 12px;border-bottom:2px solid var(--hr-line);}'
      + M + ' td{padding:9px 12px;border-bottom:1px solid var(--hr-line);vertical-align:top;}'
      + M + ' img.kt-help-img{max-width:100%;height:auto;border:1px solid var(--hr-line);border-radius:12px;}'
      + M + ' figcaption{font-size:13px;color:var(--hr-mute);text-align:center;margin-top:6px;}'
      + M + ' button.kt-help-go{display:inline-flex;align-items:center;gap:6px;min-height:32px;padding:0 14px;margin:4px 6px 4px 0;border-radius:999px;border:1px solid ' + A + ';background:var(--hr-bg);color:' + A + ';font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;}'
      + M + ' button.kt-help-go-show{background:' + A + ';color:#fff;}'
      + M + ' .kt-help-check label{display:flex;gap:10px;align-items:flex-start;padding:8px 10px;border:1px solid var(--hr-line);border-radius:10px;background:var(--hr-bg);}'
      + 'html.kt-dark ' + M + ' a,html[data-theme="dark"] ' + M + ' a{color:#5EEAD4;}'
      // "On this page" and related guides.
      + R + ' .kt-help-toc-chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 18px;padding:0 0 14px;border-bottom:1px solid var(--hr-line);max-width:68ch;}'
      + R + ' .kt-help-toc-chips .kt-help-toc-t{width:100%;font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--hr-mute);}'
      + R + ' .kt-help-toc-chips a{display:inline-flex;align-items:center;min-height:28px;padding:0 11px;border-radius:999px;border:1px solid var(--hr-line);background:var(--hr-soft);color:var(--hr-ink);font-size:13px;font-weight:600;text-decoration:none;}'
      + R + ' .kt-help-toc-chips a:hover{border-color:' + A + ';color:' + A + ';}'
      + R + ' .kt-help-more{margin-top:26px;padding-top:16px;border-top:1px solid var(--hr-line);max-width:68ch;}'
      + R + ' .kt-help-more-t{font-size:11.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--hr-mute);margin-bottom:8px;}'
      + R + ' .kt-help-more button{display:flex;align-items:center;gap:8px;width:100%;text-align:left;margin:6px 0;padding:10px 14px;border:1px solid var(--hr-line);border-radius:10px;background:var(--hr-bg);color:var(--hr-head);font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;}'
      + R + ' .kt-help-more button::after{content:"›";margin-left:auto;color:' + A + ';font-size:18px;}'
      + R + ' .kt-help-more button:hover{border-color:' + A + ';}'
      // The drawer itself.
      + '#kt-help-drawer{position:fixed;top:0;right:0;bottom:0;width:min(760px,94vw);box-sizing:border-box;background:var(--hr-bg);color:var(--hr-ink);'
      + 'padding-top:var(--kt-safe-top,env(safe-area-inset-top,0px));padding-bottom:var(--kt-safe-bottom,env(safe-area-inset-bottom,0px));'
      + 'box-shadow:-10px 0 40px rgba(15,23,42,.28);z-index:11000;display:flex;flex-direction:column;animation:kt-drawer-in .22s ease-out;}'
      + '#kt-help-drawer-bg{position:fixed;inset:0;background:rgba(15,23,42,.28);z-index:10999;animation:kt-drawer-fade .2s ease-out;}'
      + '@keyframes kt-drawer-in{from{transform:translateX(100%);}to{transform:translateX(0);}}'
      + '@keyframes kt-drawer-fade{from{opacity:0;}to{opacity:1;}}'
      + '#kt-help-drawer .kt-hd-head{flex:0 0 auto;display:flex;align-items:flex-start;gap:12px;padding:14px 16px 14px 24px;color:#fff;'
      + 'background:linear-gradient(135deg,#1B3A63 0%,#265B7A 50%,#2F8C86 100%);box-shadow:inset 0 -3px 0 #7FD3CC;}'
      + '#kt-help-drawer .kt-hd-tt{flex:1;min-width:0;}'
      + '#kt-help-drawer .kt-hd-cat{font-size:11px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;opacity:.82;margin-bottom:3px;}'
      + '#kt-help-drawer .kt-hd-title{margin:0;font-size:19px;line-height:1.25;font-weight:800;color:#fff;}'
      + '#kt-help-drawer .kt-hd-x{flex:0 0 auto;width:32px;height:32px;border-radius:50%;border:0;background:rgba(255,255,255,.16);color:#fff;font-size:15px;line-height:1;cursor:pointer;}'
      + '#kt-help-drawer .kt-hd-x:hover{background:rgba(255,255,255,.3);}'
      + '#kt-help-drawer .kt-hd-body{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:22px 32px 28px;scrollbar-width:thin;}'
      + '#kt-help-drawer .kt-hd-foot{flex:0 0 auto;padding:12px 24px;border-top:1px solid var(--hr-line);background:var(--hr-soft);}'
      + '#kt-help-drawer .kt-hd-foot a{color:var(--kt-brand-primary,#0E7490);font-weight:700;text-decoration:none;font-size:14px;}'
      + 'html.kt-dark #kt-help-drawer .kt-hd-foot a,html[data-theme="dark"] #kt-help-drawer .kt-hd-foot a{color:#5EEAD4;}'
      + '#kt-help-drawer .kt-hd-wait{color:var(--hr-mute);}'
      + '@media (max-width:600px){#kt-help-drawer{width:100vw;box-shadow:none;}#kt-help-drawer-bg{display:none;}'
      + '#kt-help-drawer .kt-hd-head{padding:12px 12px 12px 16px;}#kt-help-drawer .kt-hd-title{font-size:17px;}'
      + '#kt-help-drawer .kt-hd-body{padding:16px 16px 24px;}#kt-help-drawer .kt-hd-foot{padding:10px 16px;}'
      + M + '{font-size:15px;}' + M + ' ol > li{padding:10px 12px 10px 44px;}' + M + ' ol > li::before{left:10px;}}'
      + '@media print{#kt-help-drawer,#kt-help-drawer-bg{display:none!important;}}';
    (document.head || document.documentElement).appendChild(s);
  }

  /* Headings get ids, and a long article (3+ sections) gets "On this page" links that scroll the given scroller. */
  function tocChips(md, scroller) {
    const hs = md.querySelectorAll('h2');
    hs.forEach((h, i) => { if (!h.id) h.id = 'kth-' + i; });
    if (hs.length < 3) return null;
    const nav = document.createElement('nav');
    nav.className = 'kt-help-toc-chips';
    nav.setAttribute('aria-label', t('On this page'));
    const tt = document.createElement('div'); tt.className = 'kt-help-toc-t'; tt.textContent = t('On this page');
    nav.appendChild(tt);
    hs.forEach((h) => {
      const a = document.createElement('a');
      a.href = '#' + h.id;
      a.textContent = h.textContent;
      a.addEventListener('click', (ev) => {
        ev.preventDefault();
        if (scroller && scroller !== document.scrollingElement) scroller.scrollTo({ top: h.offsetTop - scroller.offsetTop - 8, behavior: 'smooth' });
        else h.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      nav.appendChild(a);
    });
    return nav;
  }

  /* An article's body as reading HTML: the Help centre's renderer when it is loaded (Q&As, checklists, "Show me"),
     this file's small one otherwise. The leading "# Title" goes: the title is already the heading. */
  function renderInto(md, body, slug) {
    const HM = window.KT && window.KT.helpMarkdown;
    const text = String(body || '').replace(/^\s*#\s+[^\n]*\n+/, '');
    md.innerHTML = HM ? HM.render(text) : renderMd(text);
    if (HM) { try { HM.wire(md, slug); } catch (e) {} }
  }

  /* `slug` may be a list (HR Phase 4 batch 10, 2026-11-27): the first article this reader may open is shown, so a
     "?" can name an admin guide and fall back to one a director is served. opts.related (kt-page-help.js): the page
     map's [{slug,title}] for this screen — the other guides are offered at the end. */
  function openDrawer(slug, opts) {
    readCss();
    const slugs = [].concat(slug).filter(Boolean);
    const related = (opts && Array.isArray(opts.related)) ? opts.related : [];
    slug = slugs[0] || '';
    close();

    const bg = document.createElement('div');
    bg.id = 'kt-help-drawer-bg';
    const drawer = document.createElement('div');
    drawer.id = 'kt-help-drawer';
    drawer.className = 'kt-help-read';
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');

    const header = document.createElement('div');
    header.className = 'kt-hd-head kt-dlg-head';   // the house popup band (navy to teal + teal line)
    const tt = document.createElement('div'); tt.className = 'kt-hd-tt';
    const cat = document.createElement('div'); cat.className = 'kt-hd-cat'; cat.textContent = '📖 ' + t('Help');
    const h = document.createElement('h2'); h.className = 'kt-hd-title'; h.id = 'kt-hd-title'; h.textContent = t('Loading article') + '…';
    drawer.setAttribute('aria-labelledby', 'kt-hd-title');
    tt.appendChild(cat); tt.appendChild(h);
    header.appendChild(tt);
    const x = document.createElement('button');
    x.type = 'button'; x.className = 'kt-hd-x'; x.textContent = '✕';
    x.setAttribute('aria-label', t('Close')); x.setAttribute('data-kt-iconized', '1');
    x.addEventListener('click', close);
    header.appendChild(x);
    drawer.appendChild(header);

    const body = document.createElement('div');
    body.className = 'kt-hd-body';
    body.innerHTML = '<p class="kt-hd-wait">' + t('Loading article') + '…</p>';
    drawer.appendChild(body);

    const footer = document.createElement('div');
    footer.className = 'kt-hd-foot';
    const fullBtn = document.createElement('a');
    fullBtn.href = '#help/' + slug;
    fullBtn.textContent = t('Open full article in Help & Guide') + '  →';
    fullBtn.addEventListener('click', close);
    footer.appendChild(fullBtn);
    drawer.appendChild(footer);

    document.body.appendChild(bg);
    document.body.appendChild(drawer);
    try { x.focus({ preventScroll: true }); } catch (e) {}

    // A tap outside closes it. Capture-phase pointerdown (2026-11-05): a bubbling click never arrived from a button
    // that stops its click. The listener goes with the drawer, however it closed.
    const mine = drawer;
    setTimeout(() => {
      const bgDown = (ev) => {
        if (!mine.isConnected) { document.removeEventListener('pointerdown', bgDown, true); return; }
        if (!mine.contains(ev.target) && !(ev.target.closest && ev.target.closest('.kt-help-zoom'))) {
          close();
          document.removeEventListener('pointerdown', bgDown, true);
        }
      };
      document.addEventListener('pointerdown', bgDown, true);
    }, 50);
    const esc = (ev) => { if (!mine.isConnected) { document.removeEventListener('keydown', esc); return; } if (ev.key === 'Escape') { close(); document.removeEventListener('keydown', esc); } };
    document.addEventListener('keydown', esc);

    const tryNext = (i) => getApi().get('/help/' + slugs[i]).catch((e) => {
      if (i + 1 < slugs.length) return tryNext(i + 1);
      throw e;
    });
    (slugs.length && getApi() ? tryNext(0) : Promise.reject(new Error('none')))
      .then(res => {
        if (!mine.isConnected) return;
        const a = res.article;
        fullBtn.href = '#help/' + a.slug;
        try { getApi().post('/help/' + a.slug + '/view').catch(() => {}); } catch (e) {}
        cat.textContent = a.category || t('Help');
        h.textContent = a.title;
        body.innerHTML = '';
        const md = document.createElement('div');
        md.className = 'kt-help-markdown';
        renderInto(md, a.body, a.slug);
        const toc = tocChips(md, body);
        if (toc) body.appendChild(toc);
        body.appendChild(md);
        // A "Show me" / "Open" button takes you to the screen: the drawer gets out of the way.
        md.querySelectorAll('.kt-help-go').forEach((b) => b.addEventListener('click', close));
        const more = related.filter(r => r && r.slug && r.slug !== a.slug);
        if (more.length) {
          const box = document.createElement('div');
          box.className = 'kt-help-more';
          const mt = document.createElement('div'); mt.className = 'kt-help-more-t'; mt.textContent = t('More on this page');
          box.appendChild(mt);
          more.forEach(r => {
            const b = document.createElement('button');
            b.type = 'button'; b.setAttribute('data-kt-iconized', '1');
            b.textContent = r.title || r.slug;
            b.addEventListener('click', () => openDrawer([r.slug], { related: related }));
            box.appendChild(b);
          });
          body.appendChild(box);
        }
        body.scrollTop = 0;
      })
      .catch(() => {
        if (!mine.isConnected) return;
        h.textContent = t('Help');
        body.innerHTML = '<p class="kt-hd-wait">' + t('This guide could not be opened just now. Try Help & guides from the menu.').replace(/&(?!amp;)/g, '&amp;') + '</p>';
      });
  }
  function close() {
    const d = document.getElementById('kt-help-drawer'); if (d) d.remove();
    const b = document.getElementById('kt-help-drawer-bg'); if (b) b.remove();
  }

  function renderMd(md) {
    let h = md.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    h = h.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    h = h.replace(/^## (.+)$/gm, '<h2>$1</h2>');
    h = h.replace(/^# (.+)$/gm, '');  // already shown as title
    h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
    h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/\[\[([^\]]+)\]\]/g, '<a href="#help/$1" class="kt-help-wiki">$1</a>');
    h = h.replace(/^- (.+)$/gm, '<li>$1</li>');
    h = h.replace(/(<li>.*<\/li>(?:\n<li>.*<\/li>)*)/g, '<ul>$1</ul>');
    h = h.replace(/^\d+\. (.+)$/gm, '<oli>$1</oli>');
    h = h.replace(/(<oli>.*<\/oli>(?:\n<oli>.*<\/oli>)*)/g, '<ol>$1</ol>');
    h = h.replace(/<oli>/g, '<li>').replace(/<\/oli>/g, '</li>');
    h = h.split(/\n\n+/).map(b => {
      if (b.match(/^<(h\d|ul|ol)/)) return b;
      if (b.trim() === '') return '';
      return '<p>' + b.replace(/\n/g, '<br>') + '</p>';
    }).join('');
    return h;
  }

  // Scan on load + watch for new elements
  document.addEventListener('DOMContentLoaded', () => {
    scan();
    new MutationObserver(muts => {
      muts.forEach(m => m.addedNodes.forEach(n => {
        if (n.nodeType === 1) {
          if (n.hasAttribute && n.hasAttribute('data-kt-help')) attach(n);
          if (n.querySelectorAll) scan(n);
        }
      }));
    }).observe(document.body, { childList: true, subtree: true });
  });

  window.KT = window.KT || {};
  window.KT.openHelpDrawer = openDrawer;
  window.KT.closeHelpDrawer = close;
  // The Help centre's article view reads with the same style (screen-help-v22p68.js renderArticle).
  window.KT.helpReader = { css: readCss, toc: tocChips };
})(window);
