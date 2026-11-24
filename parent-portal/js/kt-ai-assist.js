/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — "✨ AI" writing help beside the boxes people write in (2026-10-28, the AI Bundle).

   Anthony: "utilizing AI to generate daily summaries, emails and assisting with observation entries ...
   do not impact how things are done today but add this as an enhancement to allowing AI to play a role here".

   So this only ADDS a small "✨ AI" button under four kinds of box - an announcement body, a Message Centre
   message, an email, and an incident description - and only for staff whose agency has the AI Bundle
   (/auth/me ai_bundle). Choosing an action (draft from my notes, improve, shorter, friendlier, more formal,
   translate) shows a suggestion underneath; the box changes only when the person presses "Use this", and Undo
   puts their own words back. Nothing is sent or saved from here. Server: POST /ai/assist/write (AiAssistController).
   ═══════════════════════════════════════════════════════════════════ */
(function (w, d) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.aiAssist) return;

  /* Which boxes, in order (first match wins). Rich = a contenteditable editor. */
  var TARGETS = [
    { sel: 'textarea[name="description"][placeholder^="Describe the incident"]', kind: 'incident', label: 'incident description' },
    { sel: '[contenteditable="true"][data-ph="Body"]', kind: 'announcement', label: 'announcement', rich: true },
    { sel: '#kt-nc-body, #bc-body, textarea[data-body]', kind: 'message', label: 'message' },
    { sel: 'textarea[placeholder="Write your message…"]', kind: 'email', label: 'email' },
  ];
  var ACTIONS = [
    ['draft', '📝 Draft from my notes'], ['improve', '✍️ Improve the writing'], ['shorter', '✂️ Make it shorter'],
    ['friendlier', '😊 Make it friendlier'], ['formal', '👔 Make it more formal'],
  ];
  var LANGS = ['French', 'Spanish', 'Hindi', 'Punjabi', 'Urdu', 'Arabic', 'Tagalog', 'Portuguese', 'Mandarin Chinese', 'English'];

  function me() {
    try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}') || {}; } catch (e) { return {}; }
  }
  function allowed() {
    var u = me();
    if (!u.ai_bundle) return false;
    var roles = (Array.isArray(u.roles) ? u.roles : []).concat(u.primary_role ? [u.primary_role] : []);
    if (window.ktRoles && sessionStorage.getItem('kt_view_as')) roles = window.ktRoles(u);   // the previewed role
    return roles.some(function (r) { return /^(educator|centre_director|agency_admin|platform_admin)$/.test(r); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function readBox(el, rich) { return rich ? (el.innerText || '') : (el.value || ''); }
  function writeBox(el, rich, text) {
    if (rich) {
      el.innerHTML = text.split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
    } else {
      el.value = text;
    }
    try { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
  }

  var CSS = '.kt-ai-bar{display:flex;align-items:center;gap:6px;margin:6px 0 2px;position:relative;flex-wrap:wrap;}'
    + '.kt-ai-btn{display:inline-flex;align-items:center;gap:5px;height:28px;padding:0 11px;border-radius:999px;border:1px solid #DDD6FE;'
    +   'background:linear-gradient(135deg,#F5F3FF,#EDE9FE);color:#5B21B6;font-size:12.5px;font-weight:700;cursor:pointer;}'
    + '.kt-ai-btn:hover{background:#EDE9FE;}'
    + '.kt-ai-menu{position:absolute;z-index:100050;top:32px;left:0;min-width:220px;background:#fff;border:1px solid #E2E8F0;border-radius:12px;'
    +   'box-shadow:0 14px 34px -12px rgba(15,23,42,.35);padding:6px;}'
    + '.kt-ai-menu button{display:block;width:100%;text-align:left;border:0;background:none;padding:8px 10px;border-radius:8px;font-size:13px;color:#0F172A;cursor:pointer;}'
    + '.kt-ai-menu button:hover{background:#F5F3FF;}'
    + '.kt-ai-menu .kt-ai-sep{height:1px;background:#F1F5F9;margin:4px 0;}'
    + '.kt-ai-menu .kt-ai-langs{display:grid;grid-template-columns:1fr 1fr;gap:2px;}'
    + '.kt-ai-out{margin:6px 0;border:1px solid #DDD6FE;background:#FAF8FF;border-radius:12px;padding:10px 12px;}'
    + '.kt-ai-out pre{white-space:pre-wrap;font:inherit;font-size:13.5px;color:#1E293B;margin:0 0 8px;max-height:280px;overflow:auto;}'
    + '.kt-ai-out .kt-ai-act{display:flex;gap:6px;flex-wrap:wrap;}'
    + '.kt-ai-out .kt-ai-use{background:#6D28D9;color:#fff;border:0;border-radius:8px;height:30px;padding:0 12px;font-weight:700;font-size:12.5px;cursor:pointer;}'
    + '.kt-ai-out .kt-ai-no{background:#fff;color:#475569;border:1px solid #CBD5E1;border-radius:8px;height:30px;padding:0 12px;font-weight:600;font-size:12.5px;cursor:pointer;}'
    + '.kt-ai-note{font-size:11.5px;color:#94A3B8;}';
  function css() {
    if (d.getElementById('kt-ai-assist-css')) return;
    var s = d.createElement('style'); s.id = 'kt-ai-assist-css'; s.textContent = CSS; (d.head || d.documentElement).appendChild(s);
  }

  function closeMenus() { [].forEach.call(d.querySelectorAll('.kt-ai-menu'), function (m) { m.remove(); }); }
  d.addEventListener('click', function (e) { if (!e.target.closest || !e.target.closest('.kt-ai-bar')) closeMenus(); }, true);

  function attach(el, t) {
    if (el.__ktAi) return;
    el.__ktAi = true;
    css();
    var bar = d.createElement('div');
    bar.className = 'kt-ai-bar';
    bar.setAttribute('data-kt-noband', '1');
    bar.innerHTML = '<button type="button" class="kt-ai-btn" data-kt-iconized="1" data-kt-btn-free="1" aria-haspopup="menu">✨ AI</button>'
      + '<span class="kt-ai-note">Suggestions only — nothing changes until you choose “Use this”.</span>';
    el.insertAdjacentElement('afterend', bar);
    var out = null, undo = null;

    function run(action, lang) {
      closeMenus();
      var text = readBox(el, t.rich).trim();
      if (text.length < 3) {
        show('<div style="font-size:13px;color:#475569;">Type a few words first — rough notes are fine — then choose ✨ AI again.</div>', null);
        return;
      }
      show('<div style="font-size:13px;color:#6D28D9;">✨ Writing…</div>', null);
      var body = { kind: t.kind, action: action, text: text };
      if (lang) body.language = lang;
      KT.Api.post('/ai/assist/write', body).then(function (r) {
        var sug = (r && r.text) || '';
        show('<pre></pre>', sug);
      }).catch(function (e) {
        show('<div style="font-size:13px;color:#B91C1C;">' + esc((e && e.message) || 'AI writing help is not available right now.') + '</div>', null);
      });
    }
    function show(html, suggestion) {
      if (!out) { out = d.createElement('div'); out.className = 'kt-ai-out'; bar.insertAdjacentElement('afterend', out); }
      out.innerHTML = html + '<div class="kt-ai-act">'
        + (suggestion != null ? '<button type="button" class="kt-ai-use" data-kt-iconized="1" data-kt-btn-free="1">Use this</button>' : '')
        + '<button type="button" class="kt-ai-no" data-kt-iconized="1" data-kt-btn-free="1">' + (suggestion != null ? 'Discard' : 'Close') + '</button></div>';
      if (suggestion != null) { out.querySelector('pre').textContent = suggestion; }
      var use = out.querySelector('.kt-ai-use');
      if (use) use.addEventListener('click', function () {
        undo = readBox(el, t.rich);
        writeBox(el, t.rich, suggestion);
        out.innerHTML = '<div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:#475569;">✓ Used the AI suggestion. '
          + '<button type="button" class="kt-ai-no" data-kt-iconized="1" data-kt-btn-free="1">Undo</button></div>';
        out.querySelector('button').addEventListener('click', function () { if (undo != null) writeBox(el, t.rich, undo); undo = null; out.remove(); out = null; });
      });
      out.querySelector('.kt-ai-no' + (suggestion != null ? '' : '')).addEventListener('click', function () { out.remove(); out = null; });
    }

    bar.querySelector('.kt-ai-btn').addEventListener('click', function (e) {
      e.preventDefault();
      if (bar.querySelector('.kt-ai-menu')) { closeMenus(); return; }
      closeMenus();
      var m = d.createElement('div');
      m.className = 'kt-ai-menu';
      m.setAttribute('role', 'menu');
      m.innerHTML = ACTIONS.map(function (a) { return '<button type="button" data-kt-iconized="1" data-kt-btn-free="1" data-a="' + a[0] + '">' + a[1] + '</button>'; }).join('')
        + '<div class="kt-ai-sep"></div><div style="font-size:11px;font-weight:800;color:#94A3B8;padding:4px 10px;text-transform:uppercase;letter-spacing:.4px;">🌐 Translate into</div>'
        + '<div class="kt-ai-langs">' + LANGS.map(function (l) { return '<button type="button" data-kt-iconized="1" data-kt-btn-free="1" data-l="' + esc(l) + '">' + esc(l) + '</button>'; }).join('') + '</div>';
      bar.appendChild(m);
      [].forEach.call(m.querySelectorAll('[data-a]'), function (b) { b.addEventListener('click', function () { run(b.getAttribute('data-a')); }); });
      [].forEach.call(m.querySelectorAll('[data-l]'), function (b) { b.addEventListener('click', function () { run('translate', b.getAttribute('data-l')); }); });
    });
  }

  function scan() {
    if (!allowed()) return;
    for (var i = 0; i < TARGETS.length; i++) {
      var t = TARGETS[i];
      [].forEach.call(d.querySelectorAll(t.sel), function (el) {
        if (el.__ktAi || el.closest('[data-kt-nav-cover],[data-kt-refresh-snap],[data-kt-al-next]')) return;
        // First rule wins for a box two rules could match.
        if (el.__ktAiKind && el.__ktAiKind !== t.kind) return;
        el.__ktAiKind = t.kind;
        attach(el, t);
      });
    }
  }

  var pending = false;
  function soon() { if (pending) return; pending = true; setTimeout(function () { pending = false; try { scan(); } catch (e) {} }, 150); }
  KT.aiAssist = { scan: scan, allowed: allowed };
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', soon); else soon();
  try { new MutationObserver(soon).observe(d.body || d.documentElement, { childList: true, subtree: true }); } catch (e) {}
})(window, document);
