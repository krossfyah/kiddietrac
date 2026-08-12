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

  async function render(container) {
    container.innerHTML = '<div style="padding:24px;max-width:860px;margin:0 auto;color:#0F172A;">'
      + '<h2 style="margin:0 0 4px;font-size:21px;font-weight:800;">✉️ Email templates</h2>'
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

    var first = (list[0] && list[0].key) || 'provider-welcome';
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
