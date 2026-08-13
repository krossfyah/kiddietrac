/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — global document viewer (2026-08-12)

   Viewing a document keeps you inside the portal: it opens in a panel over a
   dimmed backdrop, the way Forms Manager already opened its PDFs. Handing the
   file to the browser instead opened a new tab — and in the APK an EXTERNAL
   browser, which loses the session and strands the user outside the app with
   no way back but the task switcher.

   Two ways in, so no screen has to be rewritten to benefit:

     1. KT.viewDocument(url, { title, label, filename }) — call it directly.
     2. A capture-phase click delegate that catches ordinary links to documents
        (/storage/…, .pdf, images, blob:) and routes them here instead.

   VIEW ONLY. Anything that genuinely downloads is left alone: a link carrying
   `download`, or `data-kt-download`, or an explicit `data-kt-no-viewer`, still
   behaves exactly as it did. The panel offers "Open in new tab" as the escape
   hatch for anyone who prefers the browser's own viewer or wants to save it.
   ═══════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';
  var d = w.document;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var IMAGE_RE = /\.(png|jpe?g|gif|webp|bmp|svg)(\?|#|$)/i;
  var VIEWABLE_RE = /\.(pdf|png|jpe?g|gif|webp|bmp|svg|txt)(\?|#|$)/i;

  function isImage(url) { return IMAGE_RE.test(String(url || '')); }

  // Open a URL the old way — a real new tab, or the in-app browser on the APK.
  // Used only by the panel's own "Open in new tab" button.
  function openExternally(url) {
    try {
      var C = w.Capacitor;
      var native = C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative);
      var B = C && C.Plugins && C.Plugins.Browser;
      if (native && B && B.open) { B.open({ url: url }); return; }
      if (native) { w.location.href = url; return; }
      var t = w.open(url, '_blank', 'noopener');
      if (!t) w.location.href = url;
    } catch (e) { try { w.location.href = url; } catch (_e) {} }
  }

  function view(url, opts) {
    if (!url) return null;
    opts = opts || {};
    var title = opts.title || opts.filename || 'Document';
    var label = (opts.label || 'Document').toUpperCase();

    var ov = d.createElement('div');
    // Same scrim + guard flag as the Forms Manager viewer this generalises, so it
    // dims like every other overlay in the portal and the modal guard leaves it be.
    ov.setAttribute('data-no-modal-guard', '1');
    ov.className = 'kt-scrim kt-doc-viewer';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;display:flex;align-items:center;justify-content:center;padding:18px;';

    var inner = isImage(url)
      ? '<div style="flex:1;overflow:auto;background:#0B1220;display:flex;align-items:center;justify-content:center;padding:16px;">'
        + '<img src="' + esc(url) + '" alt="' + esc(title) + '" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;background:#fff;">'
        + '</div>'
      : '<iframe src="' + esc(url) + '" title="' + esc(title) + '" style="flex:1;width:100%;border:0;background:#fff;"></iframe>';

    ov.innerHTML =
      '<div style="background:#F6F9FC;border-radius:16px;width:100%;max-width:960px;height:min(92vh,1100px);'
      + 'display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="background:#0B2545;color:#fff;padding:13px 16px;display:flex;align-items:center;gap:12px;flex:0 0 auto;">'
      +   '<div style="min-width:0;flex:1;">'
      +     '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">' + esc(label) + '</div>'
      +     '<div style="font-size:15.5px;font-weight:800;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(title) + '</div>'
      +   '</div>'
      +   '<button class="ktdv-new" type="button" data-kt-iconized="1" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap;">Open in new tab</button>'
      +   '<button class="ktdv-close" type="button" aria-label="Close" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;line-height:1;cursor:pointer;flex:0 0 auto;">✕</button>'
      + '</div>'
      + inner
      + '</div>';

    d.body.appendChild(ov);
    function close() {
      if (ov.parentNode) ov.parentNode.removeChild(ov);
      d.removeEventListener('keydown', onKey, true);
    }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
    ov.querySelector('.ktdv-close').addEventListener('click', close);
    ov.querySelector('.ktdv-new').addEventListener('click', function () { openExternally(url); });
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    d.addEventListener('keydown', onKey, true);
    return { close: close, el: ov };
  }

  // ── the delegate: ordinary document links, without touching each screen ──
  function docUrlFrom(a) {
    var href = a.getAttribute('data-kt-doc-url') || a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) return null;
    if (/^blob:/i.test(href)) return href;                       // authed fetch → blob
    var abs;
    try { abs = new URL(href, w.location.href); } catch (e) { return null; }
    // Only OUR files. A link out to another site is that site's business.
    if (abs.origin !== w.location.origin && !/kiddietrac\.com$/i.test(abs.hostname)) return null;
    if (VIEWABLE_RE.test(abs.pathname) || /\/storage\//i.test(abs.pathname)) return abs.href;
    return null;
  }

  d.addEventListener('click', function (e) {
    var t = e.target, a = null, hops = 0;
    while (t && t.nodeType === 1 && hops < 4) {
      if (t.tagName === 'A' || (t.getAttribute && t.getAttribute('data-kt-doc-url'))) { a = t; break; }
      t = t.parentElement; hops++;
    }
    if (!a) return;
    // Downloading is not viewing — leave every save/download path exactly as it was.
    if (a.hasAttribute('download') || a.hasAttribute('data-kt-download') || a.hasAttribute('data-kt-no-viewer')) return;
    if (a.closest && a.closest('.kt-doc-viewer')) return;        // the panel's own controls
    var url = docUrlFrom(a);
    if (!url) return;
    e.preventDefault();
    e.stopPropagation();
    view(url, {
      title: a.getAttribute('data-kt-doc-title') || (a.textContent || '').trim().slice(0, 90) || 'Document',
      label: a.getAttribute('data-kt-doc-label') || 'Document'
    });
  }, true);

  w.KT = w.KT || {};
  w.KT.viewDocument = view;
  w.KT.openDocumentExternally = openExternally;
})(window);
