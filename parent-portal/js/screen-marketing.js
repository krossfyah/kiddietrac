/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p34 — Marketing campaigns
   Hash: #marketing-campaigns
   Available to centre_director and agency_admin (and platform_admin
   when scoped to an active agency).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  var ASSET_BASE = ((KT.Api && KT.Api.base) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, '');
  function absUrl(p) {
    if (!p) return '';
    if (/^https?:\/\//i.test(p)) return p;
    return ASSET_BASE + p;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return d; } }

  // ── List view ──────────────────────────────────────────────────────
  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#FF8A65 0%,#7C3AED 60%,#1F6080 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📣 MARKETING</div><h1>Campaigns</h1><div class="kt-hero-sub">Newsletters, open-house invites, promotions. Compose, preview, schedule and send to the families you choose.</div>';
    wrap.appendChild(hero);

    var bar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin:18px 0;' });
    bar.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;' }, 'Drafts, scheduled, and recently sent'));
    var newBtn = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:10px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;' }, '+ New campaign');
    newBtn.addEventListener('click', function () { openComposer(null, container); });
    bar.appendChild(newBtn);
    wrap.appendChild(bar);

    var listWrap = Dom.el('div', { style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#9CA3AF;' }, 'Loading…'));

    Api.get('/marketing/campaigns').then(function (data) {
      Dom.clear(listWrap);
      if (!data.campaigns || !data.campaigns.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'No campaigns yet. Click + New campaign to compose your first.'));
        return;
      }
      data.campaigns.forEach(function (c) { listWrap.appendChild(renderRow(c, container)); });
    }).catch(function (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  function renderRow(c, container) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;' });
    row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
    row.addEventListener('mouseleave', function () { row.style.background = 'white'; });

    if (c.hero_image_url) {
      var thumb = Dom.el('img', { src: absUrl(c.hero_image_url), style: 'width:56px;height:56px;border-radius:8px;object-fit:cover;background:#F3F4F6;flex-shrink:0;' });
      row.appendChild(thumb);
    } else {
      row.appendChild(Dom.el('div', { style: 'width:56px;height:56px;border-radius:8px;background:#F3F4F6;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;' }, '📣'));
    }

    var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
    body.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:15px;color:#111827;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, c.title));
    var meta = (c.channel || 'in_portal').replace('_', ' ') + ' · ' + (c.audience || '').replace('_', ' ');
    if (c.scheduled_for) meta += ' · scheduled for ' + fmtDate(c.scheduled_for);
    else if (c.sent_at) meta += ' · sent ' + fmtDate(c.sent_at) + (c.recipient_count != null ? ' · ' + c.recipient_count + ' recipients' : '');
    body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, meta));
    row.appendChild(body);

    row.appendChild(statusPill(c.status));
    row.addEventListener('click', function () { openComposer(c, container); });
    return row;
  }

  function statusPill(s) {
    var map = {
      draft:     { bg: '#F3F4F6', fg: '#6B7280', t: 'DRAFT' },
      scheduled: { bg: '#FEF3C7', fg: '#92400E', t: 'SCHEDULED' },
      sending:   { bg: '#DBEAFE', fg: '#1E40AF', t: 'SENDING' },
      sent:      { bg: '#DCFCE7', fg: '#166534', t: 'SENT' },
      archived:  { bg: '#FEE2E2', fg: '#991B1B', t: 'ARCHIVED' },
    };
    var m = map[s] || { bg: '#F3F4F6', fg: '#6B7280', t: (s || '').toUpperCase() };
    var span = Dom.el('span', { style: 'padding:3px 10px;border-radius:999px;background:' + m.bg + ';color:' + m.fg + ';font-size:10px;font-weight:700;letter-spacing:0.5px;flex-shrink:0;' }, m.t);
    return span;
  }

  // ── Composer ───────────────────────────────────────────────────────
  function openComposer(existing, container) {
    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;' });
    var modal = Dom.el('div', { style: 'background:white;border-radius:16px;max-width:920px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 12px 36px rgba(0,0,0,.25);' });
    overlay.appendChild(modal);

    var header = Dom.el('div', { style: 'padding:18px 24px;border-bottom:1px solid #E5E7EB;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:2;' });
    header.appendChild(Dom.el('h2', { style: 'margin:0;font-size:18px;' }, existing ? 'Edit campaign' : 'New campaign'));
    var closeBtn = Dom.el('button', { style: 'background:transparent;border:none;font-size:22px;color:#6B7280;cursor:pointer;line-height:1;padding:4px 10px;' }, '×');
    closeBtn.addEventListener('click', function () { overlay.remove(); });
    header.appendChild(closeBtn);
    modal.appendChild(header);

    var body = Dom.el('div', { style: 'padding:20px 24px;' });
    modal.appendChild(body);

    // Title
    body.appendChild(labelEl('Campaign title'));
    var titleIn = Dom.el('input', { type: 'text', placeholder: 'Spring open house · April newsletter · etc.', value: existing ? existing.title : '', style: inputStyle() });
    body.appendChild(titleIn);

    // Subject (email)
    body.appendChild(labelEl('Email subject (used when channel includes email)'));
    var subjectIn = Dom.el('input', { type: 'text', placeholder: 'Save the date: our spring open house', value: existing ? (existing.subject || '') : '', style: inputStyle() });
    body.appendChild(subjectIn);

    // Audience + channel row
    var row2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;' });
    var audWrap = Dom.el('div');
    audWrap.appendChild(labelEl('Audience'));
    var audSel = Dom.el('select', { style: inputStyle() });
    [['all_families','All families'],['active_families','Currently enrolled'],['waitlist','Waitlist'],['prospects','Prospects (no active child)'],['staff','Staff only']].forEach(function (o) {
      var opt = Dom.el('option', { value: o[0] }, o[1]);
      if (existing && existing.audience === o[0]) opt.selected = true;
      audSel.appendChild(opt);
    });
    audWrap.appendChild(audSel);
    row2.appendChild(audWrap);

    var chWrap = Dom.el('div');
    chWrap.appendChild(labelEl('Channel'));
    var chSel = Dom.el('select', { style: inputStyle() });
    [['in_portal','In-portal (announcements)'],['email','Email (via scheduler)'],['both','Both']].forEach(function (o) {
      var opt = Dom.el('option', { value: o[0] }, o[1]);
      if (existing && existing.channel === o[0]) opt.selected = true;
      chSel.appendChild(opt);
    });
    chWrap.appendChild(chSel);
    row2.appendChild(chWrap);
    body.appendChild(row2);

    // Hero image
    body.appendChild(labelEl('Hero image (optional)'));
    var heroWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;margin-bottom:14px;' });
    var heroPreview = Dom.el('div', { style: 'width:84px;height:60px;border-radius:8px;background:#F3F4F6;background-size:cover;background-position:center;display:flex;align-items:center;justify-content:center;color:#9CA3AF;font-size:22px;flex-shrink:0;' });
    if (existing && existing.hero_image_url) {
      heroPreview.style.backgroundImage = 'url(' + absUrl(existing.hero_image_url) + ')';
    } else {
      heroPreview.textContent = '🖼';
    }
    heroWrap.appendChild(heroPreview);
    var heroBtn = Dom.el('button', { type: 'button', style: btnSecondary() }, existing && existing.hero_image_url ? 'Change image' : 'Upload image');
    var heroFile = Dom.el('input', { type: 'file', accept: 'image/*', style: 'display:none;' });
    var heroUrl = existing ? (existing.hero_image_url || '') : '';
    heroBtn.addEventListener('click', function () { heroFile.click(); });
    heroFile.addEventListener('change', function () {
      var f = heroFile.files[0]; if (!f) return;
      var fd = new FormData(); fd.append('image', f);
      heroBtn.disabled = true; heroBtn.textContent = 'Uploading…';
      Api.postForm('/marketing/images', fd).then(function (r) {
        heroUrl = r.url;
        heroPreview.style.backgroundImage = 'url(' + absUrl(r.url) + ')';
        heroPreview.textContent = '';
        heroBtn.textContent = 'Change image';
      }).catch(function (e) { alert('Upload failed: ' + e.message); heroBtn.textContent = 'Upload image'; })
        .finally(function () { heroBtn.disabled = false; });
    });
    heroWrap.appendChild(heroBtn);
    heroWrap.appendChild(heroFile);
    body.appendChild(heroWrap);

    // Rich-text editor
    body.appendChild(labelEl('Body'));
    body.appendChild(buildRichEditor(existing ? existing.body_html : '', function () { return null; }));

    // Schedule
    body.appendChild(labelEl('Schedule (optional — leave blank to send manually)'));
    var schedIn = Dom.el('input', { type: 'datetime-local', value: existing && existing.scheduled_for ? toDatetimeLocal(existing.scheduled_for) : '', style: inputStyle() });
    body.appendChild(schedIn);

    // Action row
    var actions = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:18px;padding-top:18px;border-top:1px solid #E5E7EB;flex-wrap:wrap;' });
    var leftBtns = Dom.el('div', { style: 'display:flex;gap:8px;' });
    if (existing) {
      var delBtn = Dom.el('button', { style: btnDanger() }, 'Delete');
      delBtn.addEventListener('click', function () {
        if (!confirm('Delete this campaign? This cannot be undone.')) return;
        Api.delete('/marketing/campaigns/' + existing.id).then(function () { overlay.remove(); render(container); });
      });
      leftBtns.appendChild(delBtn);
    }
    actions.appendChild(leftBtns);

    var rightBtns = Dom.el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;' });
    var saveBtn = Dom.el('button', { style: btnSecondary() }, 'Save draft');
    var sendBtn = Dom.el('button', { style: btnPrimary() }, 'Send now');
    rightBtns.appendChild(saveBtn);
    rightBtns.appendChild(sendBtn);
    actions.appendChild(rightBtns);
    body.appendChild(actions);

    function collect() {
      var editor = modal.querySelector('.kt-rt-editor');
      return {
        title: titleIn.value.trim(),
        subject: subjectIn.value.trim() || null,
        body_html: editor ? editor.innerHTML : '',
        hero_image_url: heroUrl || null,
        audience: audSel.value,
        channel: chSel.value,
        scheduled_for: schedIn.value ? schedIn.value.replace('T', ' ') + ':00' : null,
      };
    }

    saveBtn.addEventListener('click', function () {
      var payload = collect();
      if (!payload.title) { alert('Title is required.'); return; }
      if (!payload.body_html) { alert('Body is required.'); return; }
      payload.status = payload.scheduled_for ? 'scheduled' : 'draft';
      var promise = existing
        ? Api.patch('/marketing/campaigns/' + existing.id, payload)
        : Api.post('/marketing/campaigns', payload);
      saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
      promise.then(function () { overlay.remove(); render(container); })
        .catch(function (e) { alert('Save failed: ' + e.message); saveBtn.disabled = false; saveBtn.textContent = 'Save draft'; });
    });

    sendBtn.addEventListener('click', function () {
      if (!confirm('Send this campaign now? This cannot be undone.')) return;
      var payload = collect();
      if (!payload.title || !payload.body_html) { alert('Title and body are required before sending.'); return; }
      sendBtn.disabled = true; sendBtn.textContent = 'Sending…';
      var ensure = existing
        ? Api.patch('/marketing/campaigns/' + existing.id, payload).then(function () { return existing.id; })
        : Api.post('/marketing/campaigns', payload).then(function (r) { return r.id; });
      ensure
        .then(function (id) { return Api.post('/marketing/campaigns/' + id + '/send', {}); })
        .then(function (r) { alert('Sent to ' + r.recipients + ' recipients via ' + r.channel + '.'); overlay.remove(); render(container); })
        .catch(function (e) { alert('Send failed: ' + e.message); sendBtn.disabled = false; sendBtn.textContent = 'Send now'; });
    });

    document.body.appendChild(overlay);
  }

  function toDatetimeLocal(d) {
    try { var dt = new Date(d); return dt.toISOString().slice(0, 16); } catch (e) { return ''; }
  }

  // ── Mini rich-text editor — contentEditable + toolbar ──────────────
  function buildRichEditor(initialHtml, getContext) {
    var wrap = Dom.el('div', { style: 'border:1px solid #D1D5DB;border-radius:10px;overflow:hidden;margin-bottom:14px;background:white;' });
    var toolbar = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:4px;padding:8px;border-bottom:1px solid #E5E7EB;background:#FAFBFC;' });

    function btn(label, title, action, extraStyle) {
      var b = Dom.el('button', { type: 'button', title: title, style: 'background:white;border:1px solid #E5E7EB;border-radius:6px;padding:5px 9px;font-size:13px;cursor:pointer;color:#374151;min-width:30px;transition:background .12s,border-color .12s;' + (extraStyle || '') }, label);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep focus on editor
      b.addEventListener('click', action);
      b.addEventListener('mouseenter', function () { b.style.background = '#F3F4F6'; b.style.borderColor = '#D1D5DB'; });
      b.addEventListener('mouseleave', function () { b.style.background = 'white'; b.style.borderColor = '#E5E7EB'; });
      return b;
    }
    function selectStyle() { return 'background:white;border:1px solid #E5E7EB;border-radius:6px;padding:4px 6px;font-size:12px;color:#374151;cursor:pointer;'; }
    // v22p35: enable styleWithCSS so foreColor/hiliteColor produce inline-style
    // spans the sanitiser allows, rather than deprecated <font> tags. Also try
    // both hiliteColor (Chrome / Webkit) and backColor (Firefox) so highlight
    // works across browsers.
    function exec(cmd, val) {
      try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
      if (cmd === 'hiliteColor') {
        if (!document.execCommand('hiliteColor', false, val || null)) {
          document.execCommand('backColor', false, val || null);
        }
      } else {
        document.execCommand(cmd, false, val == null ? null : val);
      }
      editor.focus();
    }

    // ── Font family ───────────────────────────────────────────────
    var fontSel = Dom.el('select', { title: 'Font family', style: selectStyle() });
    var fonts = [
      ['', 'Default'],
      ['Inter, system-ui, sans-serif', 'Inter (clean)'],
      ['Georgia, "Times New Roman", serif', 'Georgia (serif)'],
      ['"Helvetica Neue", Arial, sans-serif', 'Helvetica'],
      ['"Courier New", monospace', 'Courier (mono)'],
      ['"Comic Sans MS", "Comic Sans", cursive', 'Comic Sans'],
      ['Verdana, sans-serif', 'Verdana'],
      ['"Trebuchet MS", sans-serif', 'Trebuchet'],
    ];
    fonts.forEach(function (f) { var o = Dom.el('option', { value: f[0] }, f[1]); fontSel.appendChild(o); });
    fontSel.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    fontSel.addEventListener('change', function () { if (fontSel.value) { exec('fontName', fontSel.value); } fontSel.selectedIndex = 0; });
    toolbar.appendChild(fontSel);

    // ── Font size (1=8px ... 7=36px in execCommand) ───────────────
    var sizeSel = Dom.el('select', { title: 'Text size', style: selectStyle() });
    [['', 'Size'], ['1', 'Tiny'], ['2', 'Small'], ['3', 'Normal'], ['4', 'Medium'], ['5', 'Large'], ['6', 'X-large'], ['7', 'Huge']].forEach(function (s) {
      sizeSel.appendChild(Dom.el('option', { value: s[0] }, s[1]));
    });
    sizeSel.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    sizeSel.addEventListener('change', function () { if (sizeSel.value) exec('fontSize', sizeSel.value); sizeSel.selectedIndex = 0; });
    toolbar.appendChild(sizeSel);
    toolbar.appendChild(divider());

    // ── Bold / italic / underline / strike ────────────────────────
    toolbar.appendChild(btn('B', 'Bold (Ctrl/Cmd+B)', function () { exec('bold'); }, 'font-weight:800;'));
    toolbar.appendChild(btn('I', 'Italic (Ctrl/Cmd+I)', function () { exec('italic'); }, 'font-style:italic;'));
    toolbar.appendChild(btn('U', 'Underline (Ctrl/Cmd+U)', function () { exec('underline'); }, 'text-decoration:underline;'));
    toolbar.appendChild(btn('S', 'Strikethrough', function () { exec('strikeThrough'); }, 'text-decoration:line-through;'));
    toolbar.appendChild(divider());

    // ── Text colour + highlight (background) ──────────────────────
    var fgInput = Dom.el('input', { type: 'color', title: 'Text colour', value: '#1F6080', style: 'width:34px;height:30px;padding:0;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;' });
    fgInput.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    fgInput.addEventListener('input', function () { exec('foreColor', fgInput.value); });
    toolbar.appendChild(fgInput);
    var bgInput = Dom.el('input', { type: 'color', title: 'Highlight colour', value: '#FEF3C7', style: 'width:34px;height:30px;padding:0;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;' });
    bgInput.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    bgInput.addEventListener('input', function () { exec('hiliteColor', bgInput.value); });
    toolbar.appendChild(bgInput);
    toolbar.appendChild(divider());

    // ── Headings + paragraph ──────────────────────────────────────
    toolbar.appendChild(btn('H1', 'Heading 1', function () { exec('formatBlock', '<h1>'); }));
    toolbar.appendChild(btn('H2', 'Heading 2', function () { exec('formatBlock', '<h2>'); }));
    toolbar.appendChild(btn('H3', 'Heading 3', function () { exec('formatBlock', '<h3>'); }));
    toolbar.appendChild(btn('P', 'Paragraph', function () { exec('formatBlock', '<p>'); }));
    toolbar.appendChild(divider());

    // ── Alignment ─────────────────────────────────────────────────
    toolbar.appendChild(btn('⬱', 'Align left',    function () { exec('justifyLeft'); }));
    toolbar.appendChild(btn('☰', 'Align centre',  function () { exec('justifyCenter'); }));
    toolbar.appendChild(btn('⬲', 'Align right',   function () { exec('justifyRight'); }));
    toolbar.appendChild(btn('☷', 'Justify',       function () { exec('justifyFull'); }));
    toolbar.appendChild(divider());

    // ── Lists + quote ─────────────────────────────────────────────
    toolbar.appendChild(btn('•', 'Bulleted list', function () { exec('insertUnorderedList'); }));
    toolbar.appendChild(btn('1.', 'Numbered list', function () { exec('insertOrderedList'); }));
    toolbar.appendChild(btn('❝', 'Block quote', function () { exec('formatBlock', '<blockquote>'); }));
    toolbar.appendChild(btn('⇥', 'Indent', function () { exec('indent'); }));
    toolbar.appendChild(btn('⇤', 'Outdent', function () { exec('outdent'); }));
    toolbar.appendChild(divider());

    // ── Line break + horizontal rule ──────────────────────────────
    toolbar.appendChild(btn('↵', 'Soft line break (no paragraph)', function () {
      editor.focus();
      document.execCommand('insertHTML', false, '<br />');
    }));
    toolbar.appendChild(btn('─', 'Horizontal rule', function () {
      editor.focus();
      document.execCommand('insertHTML', false, '<hr style="border:none;border-top:1px solid #E5E7EB;margin:14px 0;" />');
    }));
    toolbar.appendChild(divider());

    // ── Link + image ──────────────────────────────────────────────
    toolbar.appendChild(btn('🔗', 'Insert link', function () {
      var url = prompt('Link URL (https://…):');
      if (url) exec('createLink', url);
    }));
    toolbar.appendChild(btn('🚫🔗', 'Remove link', function () { exec('unlink'); }));
    var imgBtn = btn('🖼 Image', 'Insert image', function () { fileIn.click(); });
    toolbar.appendChild(imgBtn);
    var fileIn = Dom.el('input', { type: 'file', accept: 'image/*', style: 'display:none;' });
    fileIn.addEventListener('change', function () {
      var f = fileIn.files[0]; if (!f) return;
      var fd = new FormData(); fd.append('image', f);
      imgBtn.disabled = true; imgBtn.textContent = '⏳';
      Api.postForm('/marketing/images', fd).then(function (r) {
        editor.focus();
        var html = '<img src="' + absUrl(r.url) + '" alt="" style="max-width:100%;height:auto;border-radius:8px;margin:8px 0;" />';
        document.execCommand('insertHTML', false, html);
      }).catch(function (e) { alert('Image upload failed: ' + e.message); })
        .finally(function () { imgBtn.disabled = false; imgBtn.innerHTML = '🖼 Image'; });
    });
    toolbar.appendChild(fileIn);
    toolbar.appendChild(divider());

    // ── Undo / redo / clear ───────────────────────────────────────
    toolbar.appendChild(btn('↶', 'Undo', function () { exec('undo'); }));
    toolbar.appendChild(btn('↷', 'Redo', function () { exec('redo'); }));
    toolbar.appendChild(btn('clear', 'Remove formatting', function () { exec('removeFormat'); }));

    wrap.appendChild(toolbar);
    var editor = Dom.el('div', { class: 'kt-rt-editor', contenteditable: 'true', style: 'min-height:260px;padding:14px 16px;font-size:14px;line-height:1.5;color:#111827;outline:none;' });
    editor.innerHTML = initialHtml || '<p>Type your message here…</p>';
    wrap.appendChild(editor);
    return wrap;
  }

  function divider() { return Dom.el('div', { style: 'width:1px;background:#E5E7EB;margin:0 4px;' }); }

  // ── Tiny styling helpers ───────────────────────────────────────────
  function labelEl(t) { return Dom.el('label', { style: 'display:block;font-size:13px;font-weight:700;color:#374151;margin-bottom:6px;' }, t); }
  function inputStyle() { return 'width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;margin-bottom:14px;font-family:inherit;'; }
  function btnPrimary() { return 'background:#1F6080;color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }
  function btnSecondary() { return 'background:white;color:#1F6080;border:1px solid #1F6080;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }
  function btnDanger() { return 'background:white;color:#DC2626;border:1px solid #DC2626;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }

  // ── Shell registration ─────────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:marketing-campaigns', render);
    Shell.registerScreen('centre_director:marketing-campaigns', render);
    Shell.registerScreen('platform_admin:marketing-campaigns', render);
  }
  // Also expose as window.KT for the legacy shim
  KT.Marketing = { render: render };
})(window);
