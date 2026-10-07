/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Settings → Email templates (agency admin, platform admin).

   2026-10-06, Anthony: "the edit and preview under email templates should be a popup
   instead of a new page - fully sweep and i don't see all email templates being able
   to edit and preview only some?"

   The page IS the list now: every email the system sends (EmailCatalogue), grouped
   Parents / Billing / Staff / Accounts / Admin alerts / Platform. Each row has two
   actions — plain buttons in the last cell, which kt-row-actions turns into the ⋮ menu
   (CONVENTIONS.md: never hand-roll a kebab):

     ✏️ Edit     opens the editor in a popup — about 900px on a desktop, scrolling
                 inside; a full-screen sheet on a phone. Fields, merge-tag chips, Reset
                 to default, Save, Send test… and Preview. Closing it with unsaved
                 changes asks first (KT.confirm). Escape and × close it.
     👁 Preview  the email built by the code that really sends it, with sample names
                 and figures (App\Support\EmailPreviews) — in a popup that stacks over
                 the editor when opened from it.

   Two kinds of editor:
     - the registry templates (provider welcome, daily summary, payment emails…) keep
       their full section editor and their endpoints on /admin/email-template/{key};
     - every other email edits through /admin/email-library/{key}: its subject,
       heading, an opening and a closing paragraph, plus named sentences for the emails
       that have them (closure reminder, check-in reminder…).

   The popups are this screen's own layers on <body>, not Shell.Modal: Shell.Modal keeps
   ONE dialog in #modalRoot and the preview has to stack over the editor. Each layer
   carries data-no-modal-guard (kt-modal-guard would otherwise dress it as a second
   dialog) and its own ×.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = (window.KT = window.KT || {});
  var Shell = KT.Shell;
  var Api = KT.Api;
  var d = document;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  var FONTS = ['Plus Jakarta Sans', 'Arial', 'Georgia', 'Times New Roman', 'Verdana', 'Trebuchet MS', 'Courier New', 'Comic Sans MS'];
  var SIZES = [['2', 'Small'], ['3', 'Normal'], ['4', 'Large'], ['5', 'X-Large'], ['6', 'Huge']];

  // A block value may be plain text (defaults) or HTML (once edited). Show plain
  // text with line breaks; leave HTML as-is.
  function toHtml(v) { v = String(v || ''); return /[<][a-z/]/i.test(v) ? v : esc(v).replace(/\n/g, '<br>'); }

  var regUrl = function (key, suffix) { return '/admin/email-template/' + encodeURIComponent(key) + (suffix || ''); };
  var libUrl = function (key, suffix) { return '/admin/email-library/' + encodeURIComponent(key) + (suffix || ''); };

  var screenRoot = null;      // the container render() last drew into
  var lastList = null;        // the /admin/email-library answer, for re-drawing rows
  var FILTER_KEY = 'kt_et_filter';

  /* ── the list ──────────────────────────────────────────────────────── */

  async function render(container) {
    screenRoot = container;
    injectCss();
    container.innerHTML = '<div class="kt-et2-page"><div style="color:#94A3B8;padding:24px 0;">Loading every email…</div></div>';
    var data;
    try { data = await Api.get('/admin/email-library'); }
    catch (e) {
      container.innerHTML = '<div class="kt-et2-page" style="color:#B91C1C;">Could not load the emails: ' + esc(e.message) + '</div>';
      return;
    }
    lastList = data;
    draw(container, data);
  }

  function draw(container, data) {
    var c = data.counts || {};
    var filter = '';
    try { filter = sessionStorage.getItem(FILTER_KEY) || ''; } catch (e) {}

    var html = '<div class="kt-et2-page">'
      + '<div style="color:#475569;font-size:13.5px;line-height:1.6;">Every email KiddieTrac sends — <strong>' + (c.total || 0)
      + '</strong> of them — grouped by who it is for. <strong>Preview</strong> shows an email built by the code that really sends it, '
      + 'with sample names and figures. <strong>Edit</strong> changes its wording; the logo, footer and the details each email fills in stay automatic.</div>'
      + '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0 4px;">'
      +   '<input type="search" class="kt-et2-search" placeholder="Find an email…" value="' + esc(filter) + '" aria-label="Find an email" '
      +     'style="flex:1;min-width:200px;max-width:360px;height:32px;box-sizing:border-box;padding:0 11px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;">'
      +   '<span style="font-size:12.5px;color:#64748B;">' + (c.editable || 0) + ' can be reworded here · ' + (c.previewable || 0) + ' can be previewed'
      +   (c.customised ? ' · <strong style="color:#1F6080;">' + c.customised + ' customised</strong>' : '') + '</span>'
      + '</div>';

    (data.groups || []).forEach(function (g) {
      html += '<section class="kt-et2-group" data-group="' + esc(g.key) + '">'
        + '<h3 class="kt-et2-gh">' + esc(g.label) + ' <span style="color:#94A3B8;font-weight:600;">· ' + g.emails.length + '</span></h3>'
        + '<div class="kt-card"><table data-kt-no-filter data-kt-noexport><tbody>'
        + g.emails.map(rowHtml).join('')
        + '</tbody></table></div></section>';
    });
    html += '</div>';
    container.innerHTML = html;

    container.querySelectorAll('.kt-et2-act').forEach(function (b) {
      b.addEventListener('click', function () {
        var row = findRow(b.getAttribute('data-key'));
        if (!row) { return; }
        var act = b.getAttribute('data-act');
        if (act === 'edit') { openEditor(row); }
        else if (act === 'preview') { previewFromList(row); }
        else if (act === 'event') { location.hash = row.link || '#event-rsvps'; }
      });
    });
    var search = container.querySelector('.kt-et2-search');
    search.addEventListener('input', function () {
      try { sessionStorage.setItem(FILTER_KEY, search.value); } catch (e) {}
      applyFilter(container, search.value);
    });
    applyFilter(container, filter);
    try { if (KT.sweepRowActions) { KT.sweepRowActions(); } } catch (e) {}
  }

  function applyFilter(container, q) {
    q = String(q || '').trim().toLowerCase();
    container.querySelectorAll('.kt-et2-group').forEach(function (sec) {
      var shown = 0;
      sec.querySelectorAll('tr[data-key]').forEach(function (tr) {
        var hit = !q || (tr.getAttribute('data-find') || '').indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
        if (hit) { shown++; }
      });
      sec.style.display = shown ? '' : 'none';
    });
  }

  function findRow(key) {
    var out = null;
    ((lastList && lastList.groups) || []).forEach(function (g) { g.emails.forEach(function (e) { if (e.key === key) { out = e; } }); });
    return out;
  }

  function badge(text, fg, bg, bd, title) {
    return '<span class="kt-et2-badge" style="color:' + fg + ';background:' + bg + ';border-color:' + bd + ';"'
      + (title ? ' title="' + esc(title) + '"' : '') + '>' + text + '</span>';
  }

  function rowHtml(e) {
    var badges = '';
    if (e.customised) { badges += badge('● customised', '#1F6080', '#E8F3F8', '#B9DCEB', 'This email no longer goes out in the standard wording'); }
    if (e.edit === 'per-event') { badges += badge('Edited per event', '#6D28D9', '#F5F3FF', '#DDD6FE'); }
    else if (e.edit === 'none') { badges += badge('Preview only', '#64748B', '#F8FAFC', '#E2E8F0'); }
    else if (e.platform) { badges += badge('KiddieTrac-wide', '#B45309', '#FFFBEB', '#FDE68A', 'Worded once for every agency'); }
    if (e.shared_channel) { badges += badge('also push / text', '#475569', '#F1F5F9', '#E2E8F0', 'Editing here changes the email only'); }

    var acts = '';
    if (e.edit === 'per-event') {
      acts += '<button type="button" class="kt-et2-act" data-act="event" data-key="' + esc(e.key) + '" title="Open Event RSVPs">📅 Edit in Event RSVPs</button>';
    } else if (e.can_edit) {
      acts += '<button type="button" class="kt-et2-act" data-act="edit" data-key="' + esc(e.key) + '" title="Edit">✏️ Edit</button>';
    }
    if (e.can_preview) {
      acts += '<button type="button" class="kt-et2-act" data-act="preview" data-key="' + esc(e.key) + '" title="Preview">👁 Preview</button>';
    }

    var find = (e.name + ' ' + e.fires + ' ' + e.to + ' ' + e.subject + ' ' + e.key).toLowerCase();
    return '<tr data-key="' + esc(e.key) + '" data-find="' + esc(find) + '">'
      + '<td data-label="Email" style="padding:10px 12px;vertical-align:top;">'
      +   '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">'
      +     '<span style="font-size:14px;font-weight:700;color:#0F172A;">' + esc(e.name) + '</span>' + badges + '</div>'
      +   '<div style="font-size:12.5px;color:#475569;line-height:1.5;margin-top:3px;">' + esc(e.fires) + '</div>'
      +   '<div style="font-size:12px;color:#64748B;margin-top:3px;"><span style="color:#94A3B8;">To</span> ' + esc(e.to) + '</div>'
      +   (e.reason ? '<div style="font-size:12px;color:#94A3B8;margin-top:3px;">' + esc(e.reason) + '</div>' : '')
      + '</td>'
      + '<td data-label="" style="padding:8px 12px;text-align:center;white-space:nowrap;vertical-align:middle;">' + acts + '</td>'
      + '</tr>';
  }

  /* ── layers (popups) ───────────────────────────────────────────────── */

  var layers = [];
  var keyHooked = false;
  var prevHtmlOverflow = '';

  function injectCss() {
    if (d.getElementById('kt-et2-css')) { return; }
    var s = d.createElement('style');
    s.id = 'kt-et2-css';
    s.textContent = ''
      + '.kt-et2-page{padding:18px 24px 44px;max-width:1000px;margin:0 auto;color:#0F172A;}'
      + '.kt-et2-gh{font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#64748B;margin:22px 0 8px;}'
      + '.kt-et2-badge{font-size:10.5px;font-weight:800;letter-spacing:.03em;border:1px solid;border-radius:99px;padding:1px 8px;white-space:nowrap;}'
      + '.kt-et2-act{height:30px;padding:0 11px;margin:2px;border:1px solid #CBD5E1;background:#fff;border-radius:8px;font-size:12.5px;font-weight:700;color:#1F6080;cursor:pointer;}'
      + '.kt-et2-layer{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;}'
      + '.kt-et2-card{background:#fff;width:100%;max-height:calc(100vh - 48px);border-radius:14px;box-shadow:0 24px 60px rgba(15,23,42,.35);display:flex;flex-direction:column;overflow:hidden;animation:kt-et2-sheet-in .14s ease-out;}'
      + '.kt-et2-head{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #E5E7EB;flex:none;}'
      + '.kt-et2-title{flex:1;min-width:0;font-size:16px;font-weight:800;color:#0F172A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
      + '.kt-et2-x{flex:none;width:32px;height:32px;border:0;border-radius:8px;background:#F1F5F9;color:#334155;font-size:18px;line-height:1;cursor:pointer;}'
      + '.kt-et2-body{flex:1;min-height:0;overflow:auto;overscroll-behavior:contain;padding:16px 18px;}'
      + '.kt-et2-foot{flex:none;display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 16px;border-top:1px solid #E5E7EB;background:#FAFBFC;}'
      + '.kt-et2-btn{height:32px;padding:0 14px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;border:1px solid #CBD5E1;background:#fff;color:#334155;}'
      + '.kt-et2-btn.primary{background:#1F6080;border-color:#1F6080;color:#fff;}'
      + '.kt-et2-btn.ghost{border-color:#1F6080;color:#1F6080;}'
      + '.kt-et2-field{margin-bottom:16px;}'
      + '.kt-et2-label{display:flex;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:5px;}'
      + '.kt-et2-label b{font-size:12.5px;color:#334155;}'
      + '.kt-et2-in{width:100%;box-sizing:border-box;padding:8px 11px;border:1px solid #DCE3EC;border-radius:8px;font:inherit;font-size:13.5px;line-height:1.55;color:#2A3D5F;}'
      + '.kt-et2-reset{font-size:11px;color:#1F6080;background:none;border:none;cursor:pointer;font-weight:700;padding:0;}'
      + '@keyframes kt-et2-sheet-in{from{transform:translateY(8px);opacity:0;}to{transform:none;opacity:1;}}'
      + '@media (max-width:600px){'
      +   '.kt-et2-page{padding:12px 12px 32px;}'
      +   '.kt-et2-layer{padding:0;align-items:stretch;}'
      +   '.kt-et2-card{max-width:none !important;height:100%;max-height:none;border-radius:0;}'
      +   '.kt-et2-head{padding:calc(10px + env(safe-area-inset-top)) 12px 10px;}'
      +   '.kt-et2-body{padding:12px;}'
      +   '.kt-et2-foot{padding:8px 12px calc(8px + env(safe-area-inset-bottom));}'
      + '}';
    d.head.appendChild(s);
  }

  /**
   * One popup. opts: title, width (px), body (Node), foot (Node|null), onClose(),
   * canClose() → Promise<boolean> (the unsaved-changes question), backdrop (bool:
   * a click that starts AND ends on the backdrop closes it).
   */
  function openLayer(opts) {
    injectCss();
    var root = d.createElement('div');
    root.className = 'kt-et2-layer';
    // This IS a dialog with its own ×; kt-modal-guard must not dress it as another.
    root.setAttribute('data-no-modal-guard', '1');
    root.style.zIndex = String(2147480000 + layers.length * 10);
    var card = d.createElement('div');
    card.className = 'kt-et2-card';
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');
    card.setAttribute('aria-label', opts.title || 'Email');
    card.style.maxWidth = (opts.width || 900) + 'px';
    var head = d.createElement('div');
    head.className = 'kt-et2-head';
    head.innerHTML = '<div class="kt-et2-title">' + esc(opts.title || '') + '</div>'
      + '<button type="button" class="kt-et2-x" aria-label="Close" title="Close">✕</button>';
    var body = d.createElement('div');
    body.className = 'kt-et2-body';
    if (opts.body) { body.appendChild(opts.body); }
    card.appendChild(head);
    if (opts.sub) { card.appendChild(opts.sub); }
    card.appendChild(body);
    if (opts.foot) { card.appendChild(opts.foot); }
    root.appendChild(card);

    var prevFocus = d.activeElement;
    var layer = { root: root, card: card, body: body, head: head, opts: opts, closed: false };
    layer.close = async function (force) {
      if (layer.closed) { return true; }
      if (!force && opts.canClose) {
        var ok = await opts.canClose();
        if (!ok) { return false; }
      }
      layer.closed = true;
      root.remove();
      layers = layers.filter(function (l) { return l !== layer; });
      if (!layers.length) {
        d.documentElement.style.overflow = prevHtmlOverflow;
      }
      try { if (prevFocus && prevFocus.focus) { prevFocus.focus(); } } catch (e) {}
      if (opts.onClose) { opts.onClose(); }
      return true;
    };
    head.querySelector('.kt-et2-x').addEventListener('click', function () { layer.close(); });
    if (opts.backdrop) {
      var downOnBackdrop = false;
      root.addEventListener('mousedown', function (e) { downOnBackdrop = e.target === root; });
      root.addEventListener('click', function (e) { if (e.target === root && downOnBackdrop) { layer.close(); } });
    }

    if (!layers.length) {
      prevHtmlOverflow = d.documentElement.style.overflow;
      d.documentElement.style.overflow = 'hidden';
    }
    layers.push(layer);
    d.body.appendChild(root);
    if (!keyHooked) {
      keyHooked = true;
      d.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !layers.length) { return; }
        var top = layers[layers.length - 1];
        /* Only when the key is meant for OUR top layer. A KT.confirm or KT.prompt raised
           from inside it sits above it and takes its own Escape — closing the editor too
           would answer a question nobody had answered yet. */
        var t = e.target;
        var inside = t === d || t === d.body || t === d.documentElement || (t && t.nodeType === 1 && top.root.contains(t));
        if (!inside) { return; }
        e.preventDefault();
        e.stopPropagation();
        top.close();
      }, true);
    }
    setTimeout(function () {
      var f = card.querySelector('input,textarea,[contenteditable="true"],select');
      try { (f || head.querySelector('.kt-et2-x')).focus({ preventScroll: true }); } catch (e) {}
    }, 30);
    return layer;
  }

  function el(html) { var w = d.createElement('div'); w.innerHTML = html; return w.firstElementChild || w; }

  /* ── preview ───────────────────────────────────────────────────────── */

  async function fetchPreview(row, blocks, variant) {
    if (row.edit === 'sections') {
      return Api.post(regUrl(row.registry, '/preview'), blocks ? { blocks: blocks } : {});
    }
    var p = {};
    if (blocks) { p.blocks = blocks; }
    if (variant) { p.variant = variant; }
    return Api.post(libUrl(row.key, '/preview'), p);
  }

  function previewFromList(row) { openPreview(row, null); }

  /**
   * The preview popup. getBlocks (optional) returns the editor's unsaved words, so a
   * preview opened from the editor shows the draft; opened from the list it shows what
   * is saved.
   */
  async function openPreview(row, getBlocks) {
    var variants = row.variants || null;
    var variantKeys = variants ? Object.keys(variants) : [];
    var sub = el('<div style="padding:8px 16px;border-bottom:1px solid #E5E7EB;font-size:13px;line-height:1.7;color:#334155;flex:none;background:#FAFBFC;"></div>');
    var frameWrap = el('<div style="height:100%;min-height:60vh;display:flex;flex-direction:column;"></div>');
    var body = d.createElement('div');
    body.style.cssText = 'height:100%;';
    body.appendChild(frameWrap);
    var layer = openLayer({ title: '👁 ' + row.name, width: 760, body: body, sub: sub, backdrop: true });
    layer.body.style.padding = '0';
    layer.body.style.background = '#EEF1F6';

    var current = variantKeys[0] || null;
    async function load() {
      sub.innerHTML = '<span style="color:#94A3B8;">Building the email with sample data…</span>';
      frameWrap.innerHTML = '';
      var r;
      try { r = await fetchPreview(row, getBlocks ? getBlocks() : null, current); }
      catch (e) {
        sub.innerHTML = '<span style="color:#B91C1C;">' + esc(e.message || 'This preview could not be built.') + '</span>';
        return;
      }
      if (layer.closed) { return; }
      var tags = r.tags || {};
      var used = (tags.used || []).map(function (t) {
        var bad = (tags.unknown || []).indexOf(t) !== -1;
        return '<span style="font:11px ui-monospace,Menlo,monospace;background:' + (bad ? '#FEF2F2' : '#F1F5F9') + ';color:' + (bad ? '#B91C1C' : '#334155')
          + ';border:1px solid ' + (bad ? '#FECACA' : '#E2E8F0') + ';border-radius:6px;padding:0 6px;margin:0 4px 2px 0;display:inline-block;" title="'
          + (bad ? 'Unknown tag — it will be sent exactly as typed' : 'Filled in for each email') + '">{{' + esc(t) + '}}</span>';
      }).join('');
      var from = r.from || {};
      sub.innerHTML = ''
        + (variantKeys.length > 1
          ? '<div style="margin-bottom:4px;"><label style="color:#94A3B8;display:inline-block;width:62px;">Version</label>'
            + '<select class="kt-et2-variant" style="height:28px;border:1px solid #CBD5E1;border-radius:7px;font-size:12.5px;max-width:calc(100% - 70px);">'
            + variantKeys.map(function (k) { return '<option value="' + esc(k) + '"' + (k === current ? ' selected' : '') + '>' + esc(variants[k]) + '</option>'; }).join('')
            + '</select></div>'
          : '')
        + (r.subject ? '<div><span style="color:#94A3B8;display:inline-block;width:62px;">Subject</span><b>' + esc(r.subject) + '</b></div>' : '')
        + (from.address ? '<div><span style="color:#94A3B8;display:inline-block;width:62px;">From</span>' + esc(from.name || '') + ' &lt;' + esc(from.address) + '&gt;</div>' : '')
        + ((r.attachments || []).length ? '<div><span style="color:#94A3B8;display:inline-block;width:62px;">Attached</span>📎 ' + r.attachments.map(esc).join(', ') + '</div>' : '')
        + (used ? '<div style="margin-top:2px;"><span style="color:#94A3B8;display:inline-block;width:62px;vertical-align:top;">Tags</span><span style="display:inline-block;max-width:calc(100% - 70px);">' + used + '</span></div>' : '')
        + ((tags.unknown || []).length ? '<div style="color:#B91C1C;font-size:12px;">⚠ ' + tags.unknown.length + ' tag(s) this email does not know will be sent exactly as typed.</div>' : '')
        + '<div style="color:#94A3B8;font-size:11.5px;">Sample names and figures. Built by the code that sends it — nothing was sent.</div>';
      var sel = sub.querySelector('.kt-et2-variant');
      if (sel) { sel.addEventListener('change', function () { current = sel.value; load(); }); }
      var frame = d.createElement('iframe');
      // Sandboxed: no scripts, no top navigation — the email is shown, never run.
      frame.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
      frame.setAttribute('title', 'Email preview');
      frame.style.cssText = 'flex:1;width:100%;min-height:60vh;border:0;background:#EEF1F6;color-scheme:light;display:block;';
      frame.srcdoc = r.html || '<p>Empty.</p>';
      frameWrap.appendChild(frame);
    }
    load();
  }

  /* ── the editor ────────────────────────────────────────────────────── */

  async function openEditor(row) {
    var body = el('<div><div style="color:#94A3B8;padding:12px 0;">Loading…</div></div>');
    var foot = el('<div class="kt-et2-foot"></div>');
    var state = { snapshot: null, collect: null };
    var layer = openLayer({
      title: '✏️ ' + row.name, width: 900, body: body, foot: foot,
      canClose: async function () {
        if (!state.collect || state.snapshot === null) { return true; }
        if (JSON.stringify(state.collect()) === state.snapshot) { return true; }
        return !!(window.KT && KT.confirm ? await KT.confirm({
          title: 'Discard your changes?',
          description: 'You have changed the wording of “' + row.name + '” and not saved it. Closing now loses those changes.',
          okLabel: 'Discard changes', cancelLabel: 'Keep editing', tone: 'warning',
        }) : window.confirm('Discard your unsaved changes?'));
      },
    });

    var data;
    try { data = await Api.get(row.edit === 'sections' ? regUrl(row.registry) : libUrl(row.key)); }
    catch (e) { body.innerHTML = '<div style="color:#B91C1C;">Could not load: ' + esc(e.message) + '</div>'; return; }
    if (layer.closed) { return; }
    if (row.edit === 'sections') { buildRegistryEditor(layer, row, data, body, foot, state); }
    else { buildBasicEditor(layer, row, data, body, foot, state); }
  }

  function footButtons(foot) {
    foot.innerHTML = ''
      + '<button type="button" class="kt-et2-btn primary" data-f="save">Save</button>'
      + '<button type="button" class="kt-et2-btn ghost" data-f="preview">👁 Preview</button>'
      + '<button type="button" class="kt-et2-btn" data-f="test">📤 Send test…</button>'
      + '<label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#475569;cursor:pointer;" title="Off: the test uses the wording last saved">'
      +   '<input type="checkbox" data-f="usedraft" checked style="width:15px;height:15px;margin:0;"> use my unsaved changes</label>'
      + '<span data-f="status" style="font-size:12.5px;font-weight:700;flex:1;min-width:120px;text-align:right;"></span>';
    return {
      save: foot.querySelector('[data-f="save"]'),
      preview: foot.querySelector('[data-f="preview"]'),
      test: foot.querySelector('[data-f="test"]'),
      usedraft: foot.querySelector('[data-f="usedraft"]'),
      status: foot.querySelector('[data-f="status"]'),
    };
  }

  function setStatus(s, colour, text) { s.style.color = colour; s.textContent = text; }

  function tagBarHtml(tags) {
    return tags.length
      ? '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 11px;margin-bottom:14px;">'
        + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin-bottom:6px;">Merge tags — click to add to the field you are in</div>'
        + '<div style="display:flex;flex-wrap:wrap;gap:5px;">'
        + tags.map(function (t) { return '<button type="button" class="et-tag" data-tag="{{' + esc(t) + '}}" style="font-family:ui-monospace,Menlo,monospace;font-size:11.5px;background:#fff;border:1px solid #CBD5E1;border-radius:7px;padding:3px 8px;cursor:pointer;color:#334155;">{{' + esc(t) + '}}</button>'; }).join('')
        + '</div></div>'
      : '';
  }

  /* Test send: the address is asked for every time and never defaulted; the answer is
     what the mail layer did — sent, held back by this agency's settings, or failed. */
  async function sendTest(btns, endpoint, collect) {
    var me = (window.KT && KT.Auth && KT.Auth.user && KT.Auth.user()) || {};
    var to = await KT.prompt({
      title: 'Send a test email',
      description: 'Sends one copy of this email, marked [Test], with sample names and figures, to the address you enter.',
      placeholder: me.email || 'name@example.com', type: 'email', okLabel: 'Send test',
    });
    if (to === null) { return; }
    to = String(to || '').trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) { setStatus(btns.status, '#B91C1C', '✗ Enter a valid email address.'); return; }
    setStatus(btns.status, '#64748B', 'Sending a test to ' + to + '…');
    try {
      var payload = { to: to };
      if (btns.usedraft.checked) { payload.blocks = collect(); }
      var r = await Api.post(endpoint, payload);
      setStatus(btns.status, r.sent ? '#047857' : '#B45309', (r.sent ? '✓ ' : '⚠ ') + (r.message || (r.sent ? 'Test sent.' : 'Not sent.')));
    } catch (e) { setStatus(btns.status, '#B91C1C', '✗ ' + e.message); }
  }

  function wireTags(body, getActive) {
    body.querySelectorAll('.et-tag').forEach(function (btn) {
      btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
      btn.addEventListener('click', function () {
        var f = getActive();
        if (!f) { return; }
        var tag = btn.getAttribute('data-tag');
        f.focus();
        if (f.isContentEditable) { try { d.execCommand('insertText', false, tag); } catch (e) {} return; }
        var s = f.selectionStart || 0, e2 = f.selectionEnd || 0, v = f.value;
        f.value = v.slice(0, s) + tag + v.slice(e2);
        f.selectionStart = f.selectionEnd = s + tag.length;
      });
    });
  }

  function markSaved(row, customised) {
    row.customised = !!customised;
    if (screenRoot && lastList && screenRoot.isConnected) {
      var tr = screenRoot.querySelector('tr[data-key="' + row.key + '"]');
      if (tr) {
        var nw = el('<table><tbody>' + rowHtml(row) + '</tbody></table>').querySelector('tr');
        tr.parentNode.replaceChild(nw, tr);
        nw.querySelectorAll('.kt-et2-act').forEach(function (b) {
          b.addEventListener('click', function () {
            var act = b.getAttribute('data-act');
            if (act === 'edit') { openEditor(row); } else if (act === 'preview') { previewFromList(row); } else if (act === 'event') { location.hash = row.link || '#event-rsvps'; }
          });
        });
        try { if (KT.sweepRowActions) { KT.sweepRowActions(); } } catch (e) {}
      }
    }
  }

  /* Subject, heading, opening and closing — and named sentences where the email has them. */
  function buildBasicEditor(layer, row, data, body, foot, state) {
    var fields = data.fields || [], blocks = data.blocks || {}, defaults = data.defaults || {};
    var active = null;
    var intro = '<div style="font-size:12.5px;color:#475569;line-height:1.55;margin-bottom:12px;">'
      + '<b style="color:#334155;">When it is sent:</b> ' + esc(data.fires || row.fires) + '<br>'
      + '<b style="color:#334155;">To:</b> ' + esc(data.to || row.to) + '</div>'
      + (data.platform ? '<div style="font-size:12.5px;color:#92400E;background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:7px 10px;margin-bottom:12px;">KiddieTrac-wide: this wording is used for every agency.</div>' : '')
      + (row.shared_channel ? '<div style="font-size:12.5px;color:#334155;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:8px;padding:7px 10px;margin-bottom:12px;">This changes the <b>email</b> only. The push notification and text message about the same thing keep their standard wording.</div>' : '')
      + '<div style="font-size:12.5px;color:#64748B;line-height:1.55;margin-bottom:12px;">Empty fields keep the automatic wording. Write <b>**bold**</b> with two asterisks; a new line starts a new line.</div>';

    var html = intro + tagBarHtml(data.merge_tags || []) + fields.map(function (f) {
      var v = blocks[f.k] != null ? blocks[f.k] : '';
      var label = '<div class="kt-et2-label"><span><b>' + esc(f.label) + '</b>'
        + (f.when ? ' <span style="font-size:11.5px;color:#64748B;">— ' + esc(f.when) + '</span>' : '') + '</span>'
        + '<button type="button" class="kt-et2-reset" data-k="' + esc(f.k) + '">↺ Reset to default</button></div>';
      var input = f.minH
        ? '<textarea class="kt-et2-in" data-k="' + esc(f.k) + '" rows="3" style="min-height:' + f.minH + 'px;resize:vertical;" placeholder="' + esc(f.basic ? 'Nothing added' : '') + '">' + esc(v) + '</textarea>'
        : '<input class="kt-et2-in" type="text" data-k="' + esc(f.k) + '" value="' + esc(v) + '" placeholder="' + esc(f.basic ? 'Automatic' : '') + '">';
      return '<div class="kt-et2-field">' + label + input
        + (f.hint ? '<div style="font-size:11.5px;color:#94A3B8;margin-top:4px;">' + esc(f.hint) + '</div>' : '') + '</div>';
    }).join('')
      + '<div style="margin-top:4px;"><button type="button" class="kt-et2-btn" data-f="resetall">↺ Reset everything to the default</button></div>';
    body.innerHTML = html;

    body.querySelectorAll('.kt-et2-in').forEach(function (inp) {
      ['focus', 'keyup', 'mouseup'].forEach(function (ev) { inp.addEventListener(ev, function () { active = inp; }); });
    });
    wireTags(body, function () { return active || body.querySelector('.kt-et2-in'); });
    body.querySelectorAll('.kt-et2-reset').forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-k');
        var inp = body.querySelector('.kt-et2-in[data-k="' + k + '"]');
        if (inp) { inp.value = defaults[k] || ''; }
      });
    });
    body.querySelector('[data-f="resetall"]').addEventListener('click', function () {
      body.querySelectorAll('.kt-et2-in').forEach(function (inp) { inp.value = defaults[inp.getAttribute('data-k')] || ''; });
    });

    function collect() {
      var o = {};
      body.querySelectorAll('.kt-et2-in').forEach(function (inp) { o[inp.getAttribute('data-k')] = inp.value; });
      return o;
    }
    state.collect = collect;
    state.snapshot = JSON.stringify(collect());

    var btns = footButtons(foot);
    btns.save.addEventListener('click', async function () {
      setStatus(btns.status, '#64748B', 'Saving…');
      try {
        var r = await Api.put(libUrl(row.key), { blocks: collect() });
        state.snapshot = JSON.stringify(collect());
        setStatus(btns.status, '#047857', '✓ ' + (r.message || 'Saved.'));
        markSaved(row, r.customised);
      } catch (e) { setStatus(btns.status, '#B91C1C', '✗ ' + e.message); }
    });
    btns.preview.addEventListener('click', function () { openPreview(row, collect); });
    btns.test.addEventListener('click', function () { sendTest(btns, libUrl(row.key, '/test'), collect); });
  }

  /* The registry templates: the full section editor, rich text where the template has it. */
  function buildRegistryEditor(layer, row, data, body, foot, state) {
    var key = row.registry;
    var fields = data.fields || [], blocks = data.blocks || {}, defaults = data.defaults || {}, tags = data.merge_tags || [];
    var activeField = null;
    var hasRich = fields.some(function (f) { return f.rich; });

    var toolbar = hasRich
      ? '<div class="et-toolbar" style="position:sticky;top:-16px;z-index:5;display:flex;flex-wrap:wrap;gap:6px;align-items:center;background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:7px 9px;margin-bottom:12px;box-shadow:0 1px 3px rgba(15,23,42,.05);">'
        + tbBtn('bold', '<b>B</b>') + tbBtn('italic', '<i>I</i>') + tbBtn('underline', '<u>U</u>')
        + '<span style="width:1px;height:20px;background:#E2E8F0;margin:0 2px;"></span>'
        + '<select class="et-font" title="Font" style="' + selCss() + '">' + FONTS.map(function (f) { return '<option value="' + esc(f) + '">' + esc(f) + '</option>'; }).join('') + '</select>'
        + '<select class="et-size" title="Size" style="' + selCss() + '">' + SIZES.map(function (s) { return '<option value="' + s[0] + '"' + (s[0] === '3' ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') + '</select>'
        + '<label title="Text colour" style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#475569;cursor:pointer;">🎨<input type="color" class="et-color" value="#2a3d5f" style="width:26px;height:24px;border:1px solid #CBD5E1;border-radius:6px;padding:0;cursor:pointer;"></label>'
        + '<span style="width:1px;height:20px;background:#E2E8F0;margin:0 2px;"></span>'
        + tbBtn2('et-link', '🔗 Link') + tbBtn2('et-img', '🖼️ Image') + tbBtn2('et-clear', '⌫ Clear format')
        + '</div>'
      : '';

    var editors = fields.map(function (f) {
      var head = '<div class="kt-et2-label"><b>' + esc(f.label) + '</b>'
        + '<button type="button" class="kt-et2-reset" data-k="' + esc(f.k) + '">↺ Reset to default</button></div>';
      var input = f.rich
        ? '<div class="et-editor kt-et2-in" contenteditable="true" data-k="' + esc(f.k) + '" style="min-height:' + (f.minH || 80) + 'px;outline:none;">' + toHtml(blocks[f.k] || '') + '</div>'
        /* A plain field sized for more than one line is a textarea: an <input> drops the
           line breaks, which flattened a numbered list into one line (2026-09-29). */
        : (f.minH
          ? '<textarea class="et-plain kt-et2-in" data-k="' + esc(f.k) + '" rows="4" style="min-height:' + f.minH + 'px;resize:vertical;">' + esc(blocks[f.k] || '') + '</textarea>'
          : '<input class="et-plain kt-et2-in" type="text" data-k="' + esc(f.k) + '" value="' + esc(blocks[f.k] || '') + '">');
      return '<div class="kt-et2-field">' + head + input + '</div>';
    }).join('');

    body.innerHTML = '<div style="font-size:12.5px;color:#475569;line-height:1.55;margin-bottom:12px;">'
      + '<b style="color:#334155;">When it is sent:</b> ' + esc(row.fires) + '<br><b style="color:#334155;">To:</b> ' + esc(row.to) + '</div>'
      + toolbar + tagBarHtml(tags) + editors;

    body.querySelectorAll('.et-editor, .et-plain').forEach(function (fld) {
      ['focus', 'mouseup', 'keyup'].forEach(function (ev) { fld.addEventListener(ev, function () { activeField = fld; }); });
    });
    function focusField() { if (!activeField) { activeField = body.querySelector('.et-editor, .et-plain'); } if (activeField) { activeField.focus(); } }
    function cmd(name, val) { focusField(); if (activeField && activeField.classList.contains('et-editor')) { try { d.execCommand(name, false, val); } catch (e) {} } }
    wireTags(body, function () { focusField(); return activeField; });
    if (hasRich) {
      body.querySelectorAll('[data-cmd]').forEach(function (b) {
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () { cmd(b.getAttribute('data-cmd')); });
      });
      body.querySelector('.et-font').addEventListener('change', function (e) { cmd('fontName', e.target.value); });
      body.querySelector('.et-size').addEventListener('change', function (e) { cmd('fontSize', e.target.value); });
      body.querySelector('.et-color').addEventListener('input', function (e) { cmd('foreColor', e.target.value); });
      body.querySelector('.et-clear').addEventListener('click', function () { cmd('removeFormat'); });
      // A KiddieTrac dialog takes focus, so the selection is kept and put back before
      // the link or image goes in.
      var keepSel = function () { var s = window.getSelection(); return s && s.rangeCount ? s.getRangeAt(0).cloneRange() : null; };
      var restoreSel = function (r) { focusField(); if (!r) { return; } var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); };
      body.querySelector('.et-link').addEventListener('click', async function () {
        var r = keepSel();
        var u = await KT.prompt({ title: 'Insert a link', description: 'The selected text becomes a link to this address.', placeholder: 'https://…', type: 'url', okLabel: 'Insert link' });
        restoreSel(r); if (u) { cmd('createLink', u); }
      });
      body.querySelector('.et-img').addEventListener('click', async function () {
        var r = keepSel();
        var u = await KT.prompt({ title: 'Insert an image', description: 'The image at this address is placed in the email.', placeholder: 'https://…', type: 'url', okLabel: 'Insert image' });
        restoreSel(r); if (u) { cmd('insertImage', u); }
      });
    }
    body.querySelectorAll('.kt-et2-reset').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var k = btn.getAttribute('data-k');
        var ed = body.querySelector('.et-editor[data-k="' + k + '"]');
        if (ed) { ed.innerHTML = toHtml(defaults[k] || ''); return; }
        var inp = body.querySelector('.et-plain[data-k="' + k + '"]');
        if (inp) { inp.value = defaults[k] || ''; }
      });
    });

    function collect() {
      var o = {};
      fields.forEach(function (f) {
        var ed = body.querySelector('.et-editor[data-k="' + f.k + '"]');
        if (ed) { o[f.k] = ed.innerHTML; return; }
        var inp = body.querySelector('.et-plain[data-k="' + f.k + '"]');
        if (inp) { o[f.k] = inp.value; }
      });
      return o;
    }
    state.collect = collect;
    state.snapshot = JSON.stringify(collect());

    // provider-welcome's save takes the blocks at the TOP level (its original contract).
    var savePayload = function () { return key === 'provider-welcome' ? collect() : { blocks: collect() }; };
    var btns = footButtons(foot);
    btns.save.addEventListener('click', async function () {
      setStatus(btns.status, '#64748B', 'Saving…');
      try {
        await Api.put(regUrl(key), savePayload());
        state.snapshot = JSON.stringify(collect());
        setStatus(btns.status, '#047857', '✓ Saved.');
        var changed = fields.some(function (f) {
          var v = collect()[f.k]; var dv = defaults[f.k];
          return String(v || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim() !== String(dv || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
        });
        markSaved(row, changed);
      } catch (e) { setStatus(btns.status, '#B91C1C', '✗ ' + e.message); }
    });
    btns.preview.addEventListener('click', function () { openPreview(row, collect); });
    btns.test.addEventListener('click', function () { sendTest(btns, regUrl(key, '/test'), collect); });
  }

  function tbBtn(cmd, html) { return '<button type="button" data-cmd="' + cmd + '" style="' + btnCss() + '">' + html + '</button>'; }
  function tbBtn2(cls, label) { return '<button type="button" class="' + cls + '" style="' + btnCss() + 'font-weight:600;">' + label + '</button>'; }
  function btnCss() { return 'min-width:30px;height:28px;border:1px solid #CBD5E1;background:#fff;border-radius:7px;cursor:pointer;font-size:13px;color:#334155;padding:0 8px;'; }
  function selCss() { return 'height:28px;border:1px solid #CBD5E1;border-radius:7px;font-size:12px;background:#fff;color:#334155;max-width:130px;'; }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'platform_admin'].forEach(function (r) { Shell.registerScreen(r + ':email-templates', render); });
  }
  KT.EmailTemplatesScreen = { render: render, openEditor: function (key) { var r = findRow(key); if (r) { openEditor(r); } } };
})(window);
