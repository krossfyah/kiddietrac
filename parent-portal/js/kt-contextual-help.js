/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p68 — Contextual help "?" buttons
   • Add `data-kt-help="article-slug"` to any element
   • A small "?" pill appears at top-right; clicking opens article in a drawer
   • Survives screen re-renders via MutationObserver
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';
  function getApi() { return (window.KT && window.KT.Api) || null; }

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

  function openDrawer(slug) {
    let drawer = document.getElementById('kt-help-drawer');
    if (drawer) drawer.remove();

    drawer = document.createElement('div');
    drawer.id = 'kt-help-drawer';
    drawer.style.cssText = `
      position: fixed; top: 0; right: 0; bottom: 0;
      width: min(520px, 92vw);
      background: white; box-shadow: -8px 0 32px rgba(0,0,0,0.25);
      z-index: 11000; display: flex; flex-direction: column;
      animation: kt-drawer-in 0.22s ease-out;
    `;

    if (!document.getElementById('kt-drawer-anim')) {
      const css = document.createElement('style');
      css.id = 'kt-drawer-anim';
      css.textContent = `
        @keyframes kt-drawer-in { from { transform: translateX(100%); } to { transform: translateX(0); } }
      `;
      document.head.appendChild(css);
    }

    const header = document.createElement('div');
    header.style.cssText = 'padding: 18px 24px; background: linear-gradient(135deg, #1F6080, #2D7BA8); color: white; display: flex; justify-content: space-between; align-items: center;';
    const ttl = document.createElement('div');
    ttl.style.cssText = 'font-weight: 700; font-size: 14px; opacity: 0.9;';
    ttl.textContent = '📖  HELP';
    header.appendChild(ttl);
    const close = document.createElement('button');
    close.innerHTML = '×';
    close.style.cssText = 'background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 16px; font-size: 22px; cursor: pointer; line-height: 1;';
    close.addEventListener('click', () => drawer.remove());
    header.appendChild(close);
    drawer.appendChild(header);

    const body = document.createElement('div');
    body.style.cssText = 'flex: 1; overflow-y: auto; padding: 24px 28px; font-family: inherit; color: #1F2937;';
    body.innerHTML = '<p style="color:#6B7280;">Loading article…</p>';
    drawer.appendChild(body);

    const footer = document.createElement('div');
    footer.style.cssText = 'padding: 14px 24px; border-top: 1px solid #E5E7EB; background: #F9FAFB;';
    const fullBtn = document.createElement('a');
    fullBtn.href = '#help/' + slug;
    fullBtn.style.cssText = 'color: #1F6080; font-weight: 700; text-decoration: none; font-size: 14px;';
    fullBtn.textContent = 'Open full article in Help & Guide  →';
    fullBtn.addEventListener('click', () => drawer.remove());
    footer.appendChild(fullBtn);
    drawer.appendChild(footer);

    document.body.appendChild(drawer);

    // Click-out closes
    setTimeout(() => {
      const bgClick = (ev) => {
        if (!drawer.contains(ev.target)) {
          drawer.remove();
          document.removeEventListener('click', bgClick);
        }
      };
      document.addEventListener('click', bgClick);
    }, 50);

    getApi().get('/help/' + slug)
      .then(res => {
        const a = res.article;
        body.innerHTML = '';
        const cat = document.createElement('div');
        cat.style.cssText = 'font-size: 11px; font-weight: 700; letter-spacing: 0.5px; color: #8EC73C; text-transform: uppercase; margin-bottom: 8px;';
        cat.textContent = a.category;
        body.appendChild(cat);
        const h = document.createElement('h2');
        h.style.cssText = 'margin: 0 0 20px; font-size: 22px; font-weight: 800; color: #111827;';
        h.textContent = a.title;
        body.appendChild(h);
        const md = document.createElement('div');
        md.className = 'kt-help-markdown';
        md.style.cssText = 'font-size: 14px; line-height: 1.6;';
        md.innerHTML = renderMd(a.body);
        body.appendChild(md);
      })
      .catch(e => {
        body.innerHTML = '<p style="color:#c0392b;">Could not load article: ' + e.message + '</p>';
      });
  }

  function renderMd(md) {
    let h = md.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    h = h.replace(/^### (.+)$/gm, '<h4 style="margin:18px 0 6px;font-weight:700;color:#1F2937;">$1</h4>');
    h = h.replace(/^## (.+)$/gm, '<h3 style="margin:22px 0 10px;font-weight:700;color:#1F6080;font-size:16px;">$1</h3>');
    h = h.replace(/^# (.+)$/gm, '');  // already shown as title
    h = h.replace(/`([^`]+)`/g, '<code style="background:#F3F4F6;padding:1px 6px;border-radius:4px;font-size:12px;">$1</code>');
    h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/\[\[([^\]]+)\]\]/g, '<a href="#help/$1" style="color:#1F6080;font-weight:600;">$1</a>');
    h = h.replace(/^- (.+)$/gm, '<li>$1</li>');
    h = h.replace(/(<li>.*<\/li>(?:\n<li>.*<\/li>)*)/g, '<ul style="padding-left:20px;">$1</ul>');
    h = h.replace(/^\d+\. (.+)$/gm, '<oli>$1</oli>');
    h = h.replace(/(<oli>.*<\/oli>(?:\n<oli>.*<\/oli>)*)/g, '<ol style="padding-left:20px;">$1</ol>');
    h = h.replace(/<oli>/g, '<li>').replace(/<\/oli>/g, '</li>');
    h = h.split(/\n\n+/).map(b => {
      if (b.match(/^<(h\d|ul|ol)/)) return b;
      if (b.trim() === '') return '';
      return '<p style="margin:0 0 10px;">' + b.replace(/\n/g, '<br>') + '</p>';
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
})(window);
