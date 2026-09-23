/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Editable email templates (agency admin), rich-text edition.
   #77: a TEMPLATE PICKER dropdown selects which email to edit (provider
   welcome, parent daily summary, onboarding welcome, invite, announcement).
   Each template's editable blocks come from the server (`fields`); rich blocks
   get a WYSIWYG editor (bold/italic/underline, font, size, colour, link, image,
   merge tags), plain blocks a simple input. Live Preview popup + "send me a
   test". The brand frame (logo, contacts, footer) stays templated so the email
   always renders cleanly — you control the words.
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

  var activeField = null;   // the contenteditable OR input the toolbar/tags act on
  var url = function (key, suffix) { return '/admin/email-template/' + encodeURIComponent(key) + (suffix || ''); };

  var ET_TAB_KEY = 'kt_et_tab';

  async function render(container) {
    var tab = 'edit';
    try {
      var t = sessionStorage.getItem(ET_TAB_KEY);
      if (t === 'edit' || t === 'all') { tab = t; }
    } catch (e) {}

    /* role="tab" is not decoration here: kt-icon-buttons.js leaves anything inside a
       [role="tablist"] alone, and without it the iconiser rewrote "Edit" into a bare
       pencil with the word moved to a tooltip. Saying what the control IS fixes the
       display and the screen-reader announcement in one go. */
    var tabBtn = function (id, label, on) {
      return '<button type="button" class="kt-et-tab" data-tab="' + id + '"'
        + ' role="tab" aria-selected="' + (on ? 'true' : 'false') + '" style="'
        + 'background:none;border:none;border-bottom:3px solid ' + (on ? '#1F6080' : 'transparent')
        + ';color:' + (on ? '#1F6080' : '#64748B') + ';font-weight:700;font-size:14px;'
        + 'padding:9px 14px;cursor:pointer;margin-bottom:-1px;">' + label + '</button>';
    };

    container.innerHTML = '<div style="padding:24px 24px 0;max-width:980px;margin:0 auto;">'
      + '<div role="tablist" aria-label="Email templates" style="display:flex;gap:2px;'
      + 'border-bottom:1px solid #E5E7EB;">'
      + tabBtn('edit', '✏️ Edit', tab === 'edit')
      + tabBtn('all', '📚 All emails', tab === 'all')
      + '</div></div>'
      + '<div id="kt-et-pane"></div>';

    container.querySelectorAll('.kt-et-tab').forEach(function (b) {
      b.addEventListener('click', function () {
        try { sessionStorage.setItem(ET_TAB_KEY, b.dataset.tab); } catch (e) {}
        render(container);
      });
    });

    var pane = container.querySelector('#kt-et-pane');
    if (tab === 'all') { return renderCatalogue(pane); }
    return renderEditor(pane);
  }

  /**
   * The inventory. Read-only by design: this answers "what do we send?", and the four
   * templates that can actually be reworded link across to the editor rather than
   * duplicating it here.
   */
  async function renderCatalogue(container) {
    container.innerHTML = '<div style="padding:20px 24px 40px;max-width:980px;margin:0 auto;'
      + 'color:#94A3B8;">Loading the inventory…</div>';
    var d;
    try { d = await Api.get('/admin/email-catalogue'); }
    catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#B91C1C;">Could not load: '
        + esc(e.message) + '</div>';
      return;
    }

    var CAP = {
      render: ['Edit & preview', '#166534', '#F0FDF4', '#BBF7D0'],
      sample: ['Sample can be sent', '#1D4ED8', '#EFF6FF', '#BFDBFE'],
      none:   ['Documented', '#64748B', '#F8FAFC', '#E2E8F0'],
    };

    var html = '<div style="padding:18px 24px 44px;max-width:980px;margin:0 auto;color:#0F172A;">'
      + '<div style="color:#475569;font-size:13.5px;line-height:1.6;margin-bottom:4px;">'
      + 'Every email KiddieTrac can send — <strong>' + d.total + '</strong> of them, grouped by '
      + 'who receives it. Use this to review what goes out in your name.</div>'
      + '<div style="color:#94A3B8;font-size:12.5px;line-height:1.6;margin-bottom:18px;">'
      + d.editable + ' can be reworded and previewed here · ' + d.sampleable
      + ' can send you a real sample · the rest are composed as they are sent and are '
      + 'described rather than previewed, so nothing here is a mock-up.</div>';

    (d.audiences || []).forEach(function (grp) {
      html += '<div style="font-size:12px;font-weight:800;letter-spacing:.05em;'
        + 'text-transform:uppercase;color:#64748B;margin:22px 0 8px;">'
        + esc(grp.label) + ' <span style="color:#CBD5E1;">· ' + grp.count + '</span></div>';

      (grp.emails || []).forEach(function (e) {
        var cap = CAP[e.preview] || CAP.none;
        html += '<div class="kt-et-row" data-key="' + esc(e.key) + '"'
          + ' data-registry="' + esc(e.registry || '') + '"'
          + ' style="border:1px solid #EEF2F6;border-radius:10px;padding:12px 14px;'
          + 'margin-bottom:8px;background:#fff;">'

          + '<div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;">'
          + '<div style="font-size:14px;font-weight:700;flex:1;min-width:180px;">'
          + esc(e.name) + '</div>'
          + '<span style="font-size:10.5px;font-weight:800;letter-spacing:.04em;'
          + 'text-transform:uppercase;color:' + cap[1] + ';background:' + cap[2]
          + ';border:1px solid ' + cap[3] + ';border-radius:99px;padding:2px 9px;">'
          + cap[0] + '</span></div>'

          + '<div style="font-size:12.5px;color:#475569;line-height:1.6;margin-top:6px;">'
          + esc(e.fires) + '</div>'

          + '<div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:7px;'
          + 'font-size:12px;color:#64748B;">'
          + '<div><span style="color:#94A3B8;">To</span> ' + esc(e.to) + '</div>'
          + (e.subject ? '<div><span style="color:#94A3B8;">Subject</span> '
              + esc(e.subject) + '</div>' : '')
          + '</div>'

          + (e.registry
              ? '<div style="margin-top:9px;"><button type="button" class="kt-et-open"'
                + ' data-kt-iconized="1"'
                + ' data-registry="' + esc(e.registry) + '" style="background:#fff;'
                + 'border:1px solid #1F6080;color:#1F6080;border-radius:7px;padding:4px 11px;'
                + 'font-size:12px;font-weight:700;cursor:pointer;">Open in the editor →</button></div>'
              : '')

          /* The source, in small type. It is what makes this list checkable rather than
             something to take on trust — and `php artisan email:catalogue --check` fails
             if an email is added without being listed here. */
          + '<div style="margin-top:7px;font:11px ui-monospace,Menlo,monospace;color:#CBD5E1;'
          + 'word-break:break-all;">' + esc(e.source) + '</div>'
          + '</div>';
      });
    });

    html += '</div>';
    container.innerHTML = html;

    container.querySelectorAll('.kt-et-open').forEach(function (b) {
      b.addEventListener('click', function () {
        try {
          sessionStorage.setItem(ET_TAB_KEY, 'edit');
          sessionStorage.setItem('kt_et_jump', b.dataset.registry);
        } catch (e) {}
        render(container.parentNode.parentNode || document.getElementById('appMain'));
      });
    });
  }

  async function renderEditor(container) {
    container.innerHTML = '<div style="padding:20px 24px 40px;max-width:860px;margin:0 auto;color:#0F172A;">'
      + '<div style="color:#64748B;font-size:13px;margin-bottom:16px;line-height:1.5;">Pick a template and customise its words with the rich editor. The logo, contacts and footer are filled in automatically — you control the message.</div>'
      + '<div style="margin-bottom:16px;">'
      + '<label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:5px;">Template to edit</label>'
      + '<select id="et-picker" style="width:100%;max-width:420px;box-sizing:border-box;padding:10px 12px;border:1px solid #CBD5E1;border-radius:10px;font-size:14px;font-weight:600;background:#fff;color:#0F172A;"><option>Loading…</option></select>'
      + '<div id="et-desc" style="font-size:12px;color:#64748B;margin-top:6px;line-height:1.5;"></div>'
      + '</div>'
      + '<div id="et-body"><div style="color:#94A3B8;padding:24px;text-align:center;">Loading…</div></div></div>';

    var picker = container.querySelector('#et-picker');
    var descEl = container.querySelector('#et-desc');
    var list = [];
    try { var r = await Api.get('/admin/email-templates'); list = (r && r.templates) || []; }
    catch (e) { container.querySelector('#et-body').innerHTML = '<div style="color:#B91C1C;padding:20px;">Could not load templates: ' + esc(e.message) + '</div>'; return; }

    picker.innerHTML = list.map(function (t) { return '<option value="' + esc(t.key) + '">' + esc(t.label) + '</option>'; }).join('');
    function descFor(key) { var m = list.filter(function (t) { return t.key === key; })[0]; return m ? (m.description || '') : ''; }
    picker.addEventListener('change', function () { descEl.textContent = descFor(picker.value); loadTemplate(container, picker.value); });

    /* Arrived by pressing "Open in the editor" on a catalogue row — land on THAT
       template rather than the first one, or the click silently does nothing useful. */
    var jump = '';
    try {
      jump = sessionStorage.getItem('kt_et_jump') || '';
      if (jump) { sessionStorage.removeItem('kt_et_jump'); }
    } catch (e) {}
    var known = list.filter(function (t) { return t.key === jump; }).length > 0;

    var first = (known && jump) || (list[0] && list[0].key) || 'provider-welcome';
    picker.value = first; descEl.textContent = descFor(first);
    loadTemplate(container, first);
  }

  async function loadTemplate(container, key) {
    var body = container.querySelector('#et-body');
    body.innerHTML = '<div style="color:#94A3B8;padding:24px;text-align:center;">Loading…</div>';
    var data;
    try { data = await Api.get(url(key)); }
    catch (e) { body.innerHTML = '<div style="color:#B91C1C;padding:20px;">Could not load: ' + esc(e.message) + '</div>'; return; }

    var fields = data.fields || [], blocks = data.blocks || {}, defaults = data.defaults || {}, tags = data.merge_tags || [];
    activeField = null;

    var toolbar =
      '<div id="et-toolbar" style="position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;gap:6px;align-items:center;background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px;margin-bottom:14px;box-shadow:0 1px 3px rgba(15,23,42,.05);">'
      + tbBtn('bold', '<b>B</b>') + tbBtn('italic', '<i>I</i>') + tbBtn('underline', '<u>U</u>')
      + '<span style="width:1px;height:20px;background:#E2E8F0;margin:0 2px;"></span>'
      + '<select id="et-font" title="Font" style="' + selCss() + '">' + FONTS.map(function (f) { return '<option value="' + esc(f) + '">' + esc(f) + '</option>'; }).join('') + '</select>'
      + '<select id="et-size" title="Size" style="' + selCss() + '">' + SIZES.map(function (s) { return '<option value="' + s[0] + '"' + (s[0] === '3' ? ' selected' : '') + '>' + s[1] + '</option>'; }).join('') + '</select>'
      + '<label title="Text colour" style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#475569;cursor:pointer;">🎨<input type="color" id="et-color" value="#2a3d5f" style="width:26px;height:24px;border:1px solid #CBD5E1;border-radius:6px;padding:0;cursor:pointer;"></label>'
      + '<span style="width:1px;height:20px;background:#E2E8F0;margin:0 2px;"></span>'
      + tbBtn2('et-link', '🔗 Link') + tbBtn2('et-img', '🖼️ Image') + tbBtn2('et-clear', '⌫ Clear format')
      + '</div>';

    var tagBar = tags.length ?
      '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:9px 12px;margin-bottom:16px;">'
      + '<div style="font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin-bottom:7px;">Insert a merge tag (click to add to the focused block)</div>'
      + '<div style="display:flex;flex-wrap:wrap;gap:6px;">'
      + tags.map(function (t) { return '<button type="button" class="et-tag" data-tag="{{' + t + '}}" style="font-family:ui-monospace,Menlo,monospace;font-size:11.5px;background:#fff;border:1px solid #CBD5E1;border-radius:7px;padding:4px 9px;cursor:pointer;color:#334155;">{{' + esc(t) + '}}</button>'; }).join('')
      + '</div></div>' : '';

    var editors = fields.map(function (f) {
      var head = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">'
        + '<label style="font-size:12.5px;font-weight:700;color:#334155;">' + esc(f.label) + '</label>'
        + '<button type="button" class="et-reset" data-k="' + esc(f.k) + '" style="font-size:11px;color:#1F6080;background:none;border:none;cursor:pointer;font-weight:700;">↺ Reset to default</button></div>';
      var input = f.rich
        ? '<div class="et-editor" contenteditable="true" data-k="' + esc(f.k) + '" style="min-height:' + (f.minH || 80) + 'px;box-sizing:border-box;padding:11px 13px;border:1px solid #DCE3EC;border-radius:10px;font-size:14px;line-height:1.6;background:#fff;outline:none;color:#2A3D5F;">' + toHtml(blocks[f.k] || '') + '</div>'
        : '<input class="et-plain" type="text" data-k="' + esc(f.k) + '" value="' + esc(blocks[f.k] || '') + '" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #DCE3EC;border-radius:10px;font-size:13.5px;color:#2A3D5F;">';
      return '<div style="margin-bottom:18px;">' + head + input + '</div>';
    }).join('');

    body.innerHTML = toolbar + tagBar + editors
      + '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;padding-top:14px;border-top:1px solid #EEF2F6;">'
      + '<button id="et-save" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:11px 22px;font-weight:800;font-size:13.5px;cursor:pointer;">Save template</button>'
      + '<button id="et-preview" style="background:#fff;border:1.5px solid #1F6FB2;color:#1F6FB2;border-radius:10px;padding:10px 18px;font-weight:800;font-size:13px;cursor:pointer;">👁️ Preview</button>'
      + '<button id="et-test" style="background:#fff;border:1.5px solid #CBD5E1;border-radius:10px;padding:10px 18px;font-weight:700;font-size:13px;cursor:pointer;">📤 Send me a test</button>'
      + '<span id="et-status" style="font-size:13px;font-weight:700;"></span></div>';

    // Track the focused field (editor or plain input) for the toolbar + merge tags.
    body.querySelectorAll('.et-editor, .et-plain').forEach(function (el) {
      ['focus', 'mouseup', 'keyup'].forEach(function (ev) { el.addEventListener(ev, function () { activeField = el; }); });
    });
    function focusField() { if (!activeField) activeField = body.querySelector('.et-editor, .et-plain'); if (activeField) activeField.focus(); }
    function cmd(name, val) { focusField(); if (activeField && activeField.classList.contains('et-editor')) { try { d.execCommand(name, false, val); } catch (e) {} } }
    function insertTag(tag) {
      focusField();
      if (!activeField) return;
      if (activeField.classList.contains('et-editor')) { try { d.execCommand('insertText', false, tag); } catch (e) {} }
      else {   // plain input: splice at the caret
        var el = activeField, s = el.selectionStart || 0, e2 = el.selectionEnd || 0, v = el.value;
        el.value = v.slice(0, s) + tag + v.slice(e2);
        el.selectionStart = el.selectionEnd = s + tag.length; el.focus();
      }
    }

    body.querySelectorAll('[data-cmd]').forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () { cmd(b.getAttribute('data-cmd')); });
    });
    body.querySelector('#et-font').addEventListener('change', function (e) { cmd('fontName', e.target.value); });
    body.querySelector('#et-size').addEventListener('change', function (e) { cmd('fontSize', e.target.value); });
    body.querySelector('#et-color').addEventListener('input', function (e) { cmd('foreColor', e.target.value); });
    body.querySelector('#et-clear').addEventListener('click', function () { cmd('removeFormat'); });
    body.querySelector('#et-link').addEventListener('click', function () { var u = prompt('Link URL (https://…)'); if (u) cmd('createLink', u); });
    body.querySelector('#et-img').addEventListener('click', function () { var u = prompt('Image URL (https://…)'); if (u) cmd('insertImage', u); });
    body.querySelectorAll('.et-tag').forEach(function (btn) {
      btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
      btn.addEventListener('click', function () { insertTag(btn.getAttribute('data-tag')); });
    });
    body.querySelectorAll('.et-reset').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var k = btn.getAttribute('data-k');
        var ed = body.querySelector('.et-editor[data-k="' + k + '"]');
        if (ed) { ed.innerHTML = toHtml(defaults[k] || ''); return; }
        var inp = body.querySelector('.et-plain[data-k="' + k + '"]');
        if (inp) inp.value = defaults[k] || '';
      });
    });

    function collect() {
      var o = {};
      fields.forEach(function (f) {
        var ed = body.querySelector('.et-editor[data-k="' + f.k + '"]');
        if (ed) { o[f.k] = ed.innerHTML; return; }
        var inp = body.querySelector('.et-plain[data-k="' + f.k + '"]');
        if (inp) o[f.k] = inp.value;
      });
      return o;
    }
    var status = body.querySelector('#et-status');
    // provider-welcome's save endpoint takes the blocks at the TOP level (its
    // original contract); the generic templates take them under `blocks`.
    var savePayload = function () { return key === 'provider-welcome' ? collect() : { blocks: collect() }; };

    body.querySelector('#et-save').addEventListener('click', async function () {
      status.style.color = '#64748B'; status.textContent = 'Saving…';
      try { await Api.put(url(key), savePayload()); status.style.color = '#047857'; status.textContent = '✓ Saved.'; }
      catch (e) { status.style.color = '#B91C1C'; status.textContent = '✗ ' + e.message; }
    });
    body.querySelector('#et-test').addEventListener('click', async function () {
      status.style.color = '#64748B'; status.textContent = 'Sending test…';
      try { var r = await Api.post(url(key, '/test'), { blocks: collect() }); status.style.color = '#047857'; status.textContent = '✓ ' + (r.message || 'Test sent.'); }
      catch (e) { status.style.color = '#B91C1C'; status.textContent = '✗ ' + e.message; }
    });
    body.querySelector('#et-preview').addEventListener('click', async function () {
      status.style.color = '#64748B'; status.textContent = 'Building preview…';
      try { var r = await Api.post(url(key, '/preview'), { blocks: collect() }); status.textContent = ''; openPreview(r.html || '<p>Empty.</p>'); }
      catch (e) { status.style.color = '#B91C1C'; status.textContent = '✗ ' + e.message; }
    });
  }

  function tbBtn(cmd, html) { return '<button type="button" data-cmd="' + cmd + '" style="' + btnCss() + '">' + html + '</button>'; }
  function tbBtn2(id, label) { return '<button type="button" id="' + id + '" style="' + btnCss() + 'font-weight:600;">' + label + '</button>'; }
  function btnCss() { return 'min-width:30px;height:28px;border:1px solid #CBD5E1;background:#fff;border-radius:7px;cursor:pointer;font-size:13px;color:#334155;padding:0 8px;'; }
  function selCss() { return 'height:28px;border:1px solid #CBD5E1;border-radius:7px;font-size:12px;background:#fff;color:#334155;max-width:130px;'; }

  // Full-screen modal with an iframe showing the rendered email.
  function openPreview(html) {
    var ov = d.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:100000;display:flex;flex-direction:column;align-items:center;padding:24px 12px;overflow:auto;';
    var bar = d.createElement('div');
    bar.style.cssText = 'width:100%;max-width:640px;display:flex;justify-content:space-between;align-items:center;color:#fff;margin-bottom:10px;';
    bar.innerHTML = '<div style="font-weight:800;font-size:14px;">📧 Email preview</div>';
    var close = d.createElement('button');
    close.textContent = '✕ Close';
    close.style.cssText = 'background:#fff;border:0;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer;color:#0F172A;';
    close.addEventListener('click', function () { ov.remove(); });
    bar.appendChild(close);
    var frame = d.createElement('iframe');
    frame.style.cssText = 'width:100%;max-width:640px;height:80vh;border:0;border-radius:12px;background:#EEF1F6;box-shadow:0 20px 50px rgba(0,0,0,.4);';
    ov.appendChild(bar); ov.appendChild(frame);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    d.body.appendChild(ov);
    try { frame.contentWindow.document.open(); frame.contentWindow.document.write(html); frame.contentWindow.document.close(); }
    catch (e) { frame.srcdoc = html; }
  }

  if (Shell && Shell.registerScreen) {
    ['agency_admin', 'platform_admin'].forEach(function (r) { Shell.registerScreen(r + ':email-templates', render); });
  }
  KT.EmailTemplatesScreen = { render: render };
})(window);
