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

  /* True when this is the packaged app, whose web view ignores window.print().
     Checked through Capacitor rather than by sniffing the user agent, because that is
     the same signal kt-doc-viewer already trusts for openExternally(). */
  function nativePrintUnavailable() {
    try {
      var C = w.Capacitor;
      return !!(C && (C.isNativePlatform ? C.isNativePlatform() : C.isNative));
    } catch (e) { return false; }
  }

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

  /* ── generated HTML, shown in the same panel ─────────────────────────────
     view() takes a URL. Several screens instead BUILD a document — the emergency
     card, the compliance report, an invoice, a help article — and showed it with
     `window.open('', '_blank')` followed by document.write().

     That is the trap this file was written about, arriving by a second route.
     document.write() replaces the whole document, and everything the portal is
     goes with it: the router, so the bottom bar stops navigating; kt-native-ui's
     runtime StatusBar paint and dashboard.html's <meta name="theme-color">, so the
     Android/iOS status bar drops from navy to default; and any way back, because a
     written-into window has no history to go back through and close() is refused
     inside a web view.

     Reported three times over on the emergency card: "I cannot close the card",
     "cannot move to other screens using the bottom bar", "the top bar is no longer
     navy". One cause, and it is not worth diagnosing which of those a given web
     view does — keeping the portal's own document alive makes all three impossible.

     The document goes in an iframe, so its styles cannot leak into the portal and
     the portal's cannot leak into what gets printed.
     ──────────────────────────────────────────────────────────────────────── */
  function viewHtml(html, opts) {
    if (!html) return null;
    opts = opts || {};
    var title = opts.title || 'Document';
    var label = (opts.label || 'Document').toUpperCase();

    /* The embedded document usually carries its own toolbar, built for the standalone
       tab it used to open in. The panel supplies Close and Print, so hide the
       duplicate — its Close would act on the IFRAME, which is worse than useless. */
    var doc = String(html);
    if (opts.hide) {
      doc += '<style>' + String(opts.hide) + '{display:none !important;}</style>';
    }

    var ov = d.createElement('div');
    ov.setAttribute('data-no-modal-guard', '1');
    ov.className = 'kt-scrim kt-doc-viewer';
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481200;display:flex;align-items:center;justify-content:center;padding:18px;';

    ov.innerHTML =
      '<div style="background:#F6F9FC;border-radius:16px;width:100%;max-width:960px;height:min(92vh,1100px);'
      + 'display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);">'
      + '<div style="background:#0B2545;color:#fff;padding:13px 16px;display:flex;align-items:center;gap:12px;flex:0 0 auto;">'
      +   '<div style="min-width:0;flex:1;">'
      +     '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.2px;opacity:.75;">' + esc(label) + '</div>'
      +     '<div style="font-size:15.5px;font-weight:800;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(title) + '</div>'
      +   '</div>'
      // No "Open in new tab": there is no URL to open, and a new tab is the thing
      // being moved away from.
      +   '<button class="ktdv-print" type="button" data-kt-iconized="1" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap;">'
      +     (nativePrintUnavailable() && opts.externalPrint ? '🖨 Print in browser' : '🖨 Print') + '</button>'
      +   '<button class="ktdv-close" type="button" aria-label="Close" style="background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:9px;width:34px;height:34px;font-size:17px;line-height:1;cursor:pointer;flex:0 0 auto;">✕</button>'
      + '</div>'
      + '<iframe class="ktdv-frame" title="' + esc(title) + '" style="flex:1;width:100%;border:0;background:#fff;"></iframe>'
      + '</div>';

    d.body.appendChild(ov);

    /* srcdoc rather than document.write into the frame: one assignment, no open/close
       dance, and the frame keeps its own origin-less sandbox for styling purposes. */
    var frame = ov.querySelector('.ktdv-frame');
    try { frame.srcdoc = doc; } catch (e) {
      try {
        var fd = frame.contentWindow.document;
        fd.open(); fd.write(doc); fd.close();
      } catch (e2) {}
    }

    function close() {
      if (ov.parentNode) ov.parentNode.removeChild(ov);
      d.removeEventListener('keydown', onKey, true);
    }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }

    ov.querySelector('.ktdv-close').addEventListener('click', close);
    ov.querySelector('.ktdv-print').addEventListener('click', function (ev) {
      /* IN THE APP, window.print() DOES NOTHING.

         Neither Android's web view nor iOS's implements it — a host app has to drive
         the platform's own print service, which needs a native rebuild this one cannot
         have. Calling it there is a button that silently fails, which is worse than no
         button. So on a native platform the document is handed to the device's real
         browser through the Capacitor Browser plugin, where Print works normally; the
         caller supplies `externalPrint`, a function returning a promise of a URL that
         browser can open without a session. */
      if (nativePrintUnavailable() && opts.externalPrint) {
        var btn = ev.currentTarget;
        var was = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Opening…';
        Promise.resolve()
          .then(function () { return opts.externalPrint(); })
          .then(function (url) {
            if (url) { openExternally(url); }
            else { throw new Error('no link'); }
          })
          .catch(function (e) {
            // Say so rather than appear to have worked.
            btn.textContent = 'Could not open';
            setTimeout(function () { btn.textContent = was; btn.disabled = false; }, 2200);
            return;
          })
          .then(function () {
            if (btn.textContent === 'Opening…') { btn.textContent = was; btn.disabled = false; }
          });
        return;
      }
      // Printing the FRAME prints the document, not the portal behind it.
      try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) {}
    });
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    d.addEventListener('keydown', onKey, true);

    return { close: close, el: ov, frame: frame };
  }

  /* A stand-in for `window.open('', '_blank')`.

     Ten screens build a document and write it into a blank window. Rewriting each of
     those long HTML concatenations to hand viewHtml a string would be a big diff over
     code that is fiddly and rarely touched — and every one of them would be a chance to
     drop a closing tag.

     So this answers the same small API those call sites actually use — document.open,
     document.write, document.close, document.body.innerHTML, print(), close() — and
     renders into the portal's own panel instead of a window. A call site changes one
     line and nothing else.

     Writes are buffered and flushed on a microtask, so the pattern of writing a
     "Loading…" placeholder and replacing it a moment later still shows both. */
  function docWindow(opts) {
    opts = opts || {};
    var buf = '';
    var handle = null;
    var queued = false;

    function paint() {
      queued = false;
      var html = buf || '<p style="font-family:sans-serif;padding:20px;color:#64748B;">Loading…</p>';
      if (handle && handle.frame) {
        try { handle.frame.srcdoc = html + (opts.hide ? '<style>' + opts.hide + '{display:none !important;}</style>' : ''); return; }
        catch (e) { /* fall through and rebuild */ }
      }
      handle = viewHtml(html, opts);
    }

    function schedule() {
      if (queued) return;
      queued = true;
      // A microtask, so a run of .write() calls paints once.
      Promise.resolve().then(paint);
    }

    var bodyShim = {
      get innerHTML() { return buf; },
      set innerHTML(v) { buf = String(v == null ? '' : v); schedule(); },
    };

    return {
      document: {
        open: function () { buf = ''; },
        write: function (x) { buf += (x == null ? '' : String(x)); schedule(); },
        writeln: function (x) { buf += (x == null ? '' : String(x)) + '\n'; schedule(); },
        close: function () { schedule(); },
        get body() { return bodyShim; },
      },
      focus: function () { try { handle && handle.frame && handle.frame.contentWindow.focus(); } catch (e) {} },
      print: function () {
        try { handle.frame.contentWindow.focus(); handle.frame.contentWindow.print(); } catch (e) {}
      },
      close: function () { try { handle && handle.close(); } catch (e) {} },
      get closed() { return !handle || !handle.el || !handle.el.parentNode; },
    };
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
  w.KT.viewHtml = viewHtml;
  w.KT.docWindow = docWindow;
  w.KT.openDocumentExternally = openExternally;
})(window);
