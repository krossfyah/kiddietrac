/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Knowledge base (2026-08-13)

   The agency's OWN articles: how this centre does things, what to tell a
   parent who asks X, where the spare key lives. Everyone in the agency can
   read them and everyone can write one — a nursery's knowledge sits with the
   educators, not with whoever happens to be an admin.

   Deliberately separate from Help, which ships with the product and is
   role-filtered from files on disk. Help answers "how does KiddieTrac work";
   this answers "how do WE work". Help links here at the top of its page.

   Screens (registered for every role):
     <role>:knowledge-base            → list + search
     the article view and the editor are states of the same screen, so the
     back button and a re-render never strand you mid-edit.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api;
  var Shell = KT.Shell;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function toast(icon, title, body, colour) {
    if (KT.toast) KT.toast(icon, title, body, colour);
    else window.alert(title + (body ? ' — ' + body : ''));
  }

  // Agency timezone, always — never the device's. See kt-tz.js.
  function when(ts) {
    if (!ts) return '';
    try {
      if (KT.fmtDate) return KT.fmtDate(ts);
      var tz = KT.tz ? KT.tz() : 'America/Toronto';
      return new Intl.DateTimeFormat('en-CA', {
        dateStyle: 'medium', timeStyle: 'short', timeZone: tz,
      }).format(new Date(String(ts).replace(' ', 'T') + (/[Z+]/.test(String(ts)) ? '' : 'Z')));
    } catch (e) { return String(ts).slice(0, 16); }
  }

  // Articles are written as plain text. Render paragraphs and line breaks, and
  // linkify bare URLs — but never inject raw HTML: anyone in the agency can write
  // one of these, and they are read by everyone else in it.
  function bodyHtml(text) {
    return esc(text)
      .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener" style="color:#1F6FB2;">$1</a>')
      .split(/\n{2,}/).map(function (p) {
        return '<p style="margin:0 0 12px;line-height:1.65;">' + p.replace(/\n/g, '<br>') + '</p>';
      }).join('');
  }

  var CARD = 'background:#fff;border:1px solid #E7EBF0;border-radius:14px;padding:16px 18px;box-shadow:0 1px 3px rgba(15,23,42,.05);';
  var FIELD = 'width:100%;padding:10px 12px;border:1.5px solid #E2E8F0;border-radius:10px;font-size:14px;box-sizing:border-box;background:#fff;color:#0F172A;font-family:inherit;';
  var LBL = 'display:block;font-size:12px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.3px;margin:0 0 6px;';

  function render(main) {
    var state = { q: '', category: '', view: 'list', article: null };

    main.innerHTML =
      '<div style="max-width:900px;">'
      + '<div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:6px;">'
      +   '<div style="flex:1;min-width:220px;">'
      +     '<h1 style="margin:0 0 4px;font-size:24px;font-weight:800;color:#0F172A;">📚 Knowledge base</h1>'
      +     '<div style="font-size:13.5px;color:#64748B;">Your team\'s own articles — how things are done here. Anyone can add one.</div>'
      +   '</div>'
      +   '<button id="kb-new" type="button" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:11px 18px;font-weight:800;font-size:13.5px;cursor:pointer;">＋ New article</button>'
      + '</div>'
      + '<div id="kb-tools" style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0 14px;">'
      +   '<input id="kb-q" type="search" placeholder="Search articles…" style="' + FIELD + 'flex:1;min-width:200px;">'
      +   '<select id="kb-cat" style="' + FIELD + 'width:auto;min-width:150px;"><option value="">All categories</option></select>'
      + '</div>'
      + '<div id="kb-body"></div>'
      + '</div>';

    var host = main.querySelector('#kb-body');
    var qEl = main.querySelector('#kb-q');
    var catEl = main.querySelector('#kb-cat');
    var tools = main.querySelector('#kb-tools');
    var newBtn = main.querySelector('#kb-new');

    function setTools(visible) {
      tools.style.display = visible ? 'flex' : 'none';
      newBtn.style.display = visible ? '' : 'none';
    }

    // ── list ────────────────────────────────────────────────────────────
    function loadList() {
      state.view = 'list';
      setTools(true);
      host.innerHTML = '<div style="padding:24px;text-align:center;color:#94A3B8;font-size:13.5px;">Loading…</div>';
      var qs = [];
      if (state.q) qs.push('q=' + encodeURIComponent(state.q));
      if (state.category) qs.push('category=' + encodeURIComponent(state.category));
      Api.get('/kb' + (qs.length ? '?' + qs.join('&') : ''))
        .then(function (d) { renderList(d); })
        .catch(function (e) {
          host.innerHTML = '<div style="' + CARD + 'color:#B45309;">Could not load the knowledge base'
            + (e && e.message ? ' — ' + esc(e.message) : '') + '.</div>';
        });
    }

    function renderList(d) {
      var rows = (d && d.data) || [];
      // Keep the category filter in step with what actually exists.
      var cats = (d && d.categories) || [];
      var current = catEl.value;
      catEl.innerHTML = '<option value="">All categories</option>'
        + cats.map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
      catEl.value = current;

      if (!rows.length) {
        host.innerHTML = '<div style="' + CARD + 'text-align:center;padding:34px 18px;">'
          + '<div style="font-size:34px;margin-bottom:8px;">📚</div>'
          + '<div style="font-weight:800;color:#0F172A;margin-bottom:4px;">'
          + (state.q || state.category ? 'Nothing matched that search' : 'No articles yet')
          + '</div><div style="font-size:13.5px;color:#64748B;">'
          + (state.q || state.category
              ? 'Try a different word, or clear the filters.'
              : 'Write the first one — anything the team keeps having to explain twice.')
          + '</div></div>';
        return;
      }

      host.innerHTML = '<div style="font-size:12.5px;color:#94A3B8;margin-bottom:10px;">'
        + rows.length + ' article' + (rows.length === 1 ? '' : 's') + '</div>'
        + rows.map(function (a) {
            return '<div class="kb-item" data-id="' + a.id + '" style="' + CARD + 'margin-bottom:12px;cursor:pointer;">'
              + '<div style="display:flex;align-items:flex-start;gap:10px;">'
              +   '<div style="flex:1;min-width:0;">'
              +     '<div style="font-weight:800;font-size:15.5px;color:#0F172A;margin-bottom:3px;">' + esc(a.title) + '</div>'
              +     '<div style="font-size:13px;color:#64748B;line-height:1.5;">' + esc(String(a.excerpt || '').slice(0, 180)) + (String(a.excerpt || '').length > 180 ? '…' : '') + '</div>'
              +   '</div>'
              +   (a.category ? '<span style="flex:0 0 auto;background:#EFF6FF;color:#1F6FB2;border:1px solid #BFDBFE;border-radius:999px;padding:4px 11px;font-size:11.5px;font-weight:800;">' + esc(a.category) + '</span>' : '')
              + '</div>'
              + '<div style="margin-top:10px;font-size:11.5px;color:#94A3B8;">'
              +   (a.author_name ? esc(a.author_name) + ' · ' : '') + 'updated ' + esc(when(a.updated_at))
              +   (a.views ? ' · ' + a.views + ' view' + (a.views === 1 ? '' : 's') : '')
              + '</div></div>';
          }).join('');

      [].slice.call(host.querySelectorAll('.kb-item')).forEach(function (el) {
        el.addEventListener('click', function () { openArticle(el.getAttribute('data-id')); });
      });
    }

    // ── one article ─────────────────────────────────────────────────────
    function openArticle(id) {
      setTools(false);
      host.innerHTML = '<div style="padding:24px;text-align:center;color:#94A3B8;font-size:13.5px;">Loading…</div>';
      Api.get('/kb/' + id).then(function (d) {
        var a = d.article;
        state.view = 'article';
        state.article = a;
        host.innerHTML =
          '<button id="kb-back" type="button" style="background:none;border:0;color:#1F6FB2;font-weight:700;font-size:13.5px;cursor:pointer;padding:0;margin-bottom:12px;">← All articles</button>'
          + '<div style="' + CARD + '">'
          +   '<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:6px;">'
          +     '<h2 style="flex:1;margin:0;font-size:21px;font-weight:800;color:#0F172A;">' + esc(a.title) + '</h2>'
          +     (a.category ? '<span style="flex:0 0 auto;background:#EFF6FF;color:#1F6FB2;border:1px solid #BFDBFE;border-radius:999px;padding:4px 11px;font-size:11.5px;font-weight:800;">' + esc(a.category) + '</span>' : '')
          +   '</div>'
          +   '<div style="font-size:12px;color:#94A3B8;margin-bottom:16px;">'
          +     (a.author_name ? 'by ' + esc(a.author_name) + ' · ' : '') + 'updated ' + esc(when(a.updated_at))
          +     ' · ' + (a.views || 0) + ' view' + ((a.views || 0) === 1 ? '' : 's')
          +   '</div>'
          +   '<div style="font-size:14.5px;color:#1E293B;">' + bodyHtml(a.body) + '</div>'
          +   (a.tags ? '<div style="margin-top:14px;display:flex;gap:6px;flex-wrap:wrap;">'
                + String(a.tags).split(',').map(function (t) {
                    t = t.trim(); if (!t) return '';
                    return '<span style="background:#F1F5F9;color:#475569;border-radius:999px;padding:3px 10px;font-size:11.5px;font-weight:700;">' + esc(t) + '</span>';
                  }).join('') + '</div>' : '')
          +   (a.can_edit
                ? '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #F1F5F9;display:flex;gap:8px;">'
                  + '<button id="kb-edit" type="button" style="background:#fff;color:#1F6080;border:1.5px solid #1F6080;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer;">Edit</button>'
                  + '<button id="kb-del" type="button" style="background:#fff;color:#B91C1C;border:1.5px solid #FECACA;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer;">Remove</button>'
                  + '</div>'
                : '')
          + '</div>';

        host.querySelector('#kb-back').addEventListener('click', loadList);
        var ed = host.querySelector('#kb-edit');
        if (ed) ed.addEventListener('click', function () { openEditor(a); });
        var del = host.querySelector('#kb-del');
        if (del) del.addEventListener('click', function () { removeArticle(a); });
      }).catch(function (e) {
        host.innerHTML = '<div style="' + CARD + 'color:#B45309;">Could not open that article'
          + (e && e.message ? ' — ' + esc(e.message) : '') + '.</div>';
      });
    }

    async function removeArticle(a) {
      var ok = KT.confirm
        ? await KT.confirm({ title: 'Remove this article?', description: '“' + a.title + '” will no longer appear in the knowledge base.', tone: 'danger' })
        : window.confirm('Remove “' + a.title + '”?');
      if (!ok) return;
      try {
        await Api.delete('/kb/' + a.id);
        toast('🗑️', 'Article removed', a.title, '#16A34A');
        loadList();
      } catch (e) {
        toast('⚠️', 'Could not remove it', (e && e.message) || 'error', '#DC2626');
      }
    }

    // ── write / edit ────────────────────────────────────────────────────
    function openEditor(existing) {
      setTools(false);
      state.view = 'editor';
      var a = existing || { title: '', body: '', category: '', tags: '' };
      host.innerHTML =
        '<button id="kb-back" type="button" style="background:none;border:0;color:#1F6FB2;font-weight:700;font-size:13.5px;cursor:pointer;padding:0;margin-bottom:12px;">← All articles</button>'
        + '<div style="' + CARD + '">'
        +   '<div style="font-weight:800;font-size:17px;color:#0F172A;margin-bottom:16px;">'
        +     (existing ? 'Edit article' : 'New article') + '</div>'
        +   '<div style="margin-bottom:14px;"><label style="' + LBL + '" for="kb-title">Title</label>'
        +     '<input id="kb-title" type="text" maxlength="200" placeholder="e.g. What to do when a parent arrives late" style="' + FIELD + '"></div>'
        +   '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;">'
        +     '<div style="flex:1;min-width:180px;"><label style="' + LBL + '" for="kb-cat-in">Category <span style="font-weight:600;text-transform:none;color:#94A3B8;">(optional)</span></label>'
        +       '<input id="kb-cat-in" type="text" maxlength="60" list="kb-cats" placeholder="e.g. Daily routine" style="' + FIELD + '"><datalist id="kb-cats"></datalist></div>'
        +     '<div style="flex:1;min-width:180px;"><label style="' + LBL + '" for="kb-tags">Tags <span style="font-weight:600;text-transform:none;color:#94A3B8;">(comma separated)</span></label>'
        +       '<input id="kb-tags" type="text" maxlength="200" placeholder="e.g. parents, pickup" style="' + FIELD + '"></div>'
        +   '</div>'
        +   '<div style="margin-bottom:16px;"><label style="' + LBL + '" for="kb-text">Article</label>'
        +     '<textarea id="kb-text" rows="14" maxlength="20000" placeholder="Write it the way you would explain it to a new educator on their first day." style="' + FIELD + 'resize:vertical;line-height:1.6;"></textarea>'
        +     '<div style="font-size:11.5px;color:#94A3B8;margin-top:6px;">Plain text. Leave a blank line between paragraphs; links become clickable.</div></div>'
        +   '<div style="display:flex;gap:8px;align-items:center;">'
        +     '<button id="kb-save" type="button" style="background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);color:#fff;border:0;border-radius:10px;padding:11px 20px;font-weight:800;font-size:13.5px;cursor:pointer;">'
        +       (existing ? 'Save changes' : 'Publish article') + '</button>'
        +     '<button id="kb-cancel" type="button" style="background:#fff;color:#475569;border:1.5px solid #E2E8F0;border-radius:10px;padding:11px 18px;font-weight:700;font-size:13.5px;cursor:pointer;">Cancel</button>'
        +     '<span id="kb-msg" style="font-size:12.5px;font-weight:700;"></span>'
        +   '</div>'
        + '</div>';

      // Values go in as VALUES, never interpolated into the markup — an apostrophe
      // in a title should not be able to break the form, let alone anything worse.
      host.querySelector('#kb-title').value = a.title || '';
      host.querySelector('#kb-cat-in').value = a.category || '';
      host.querySelector('#kb-tags').value = a.tags || '';
      host.querySelector('#kb-text').value = a.body || '';

      // Offer the categories already in use, so they converge instead of sprawling.
      Api.get('/kb').then(function (d) {
        var dl = host.querySelector('#kb-cats');
        if (dl) dl.innerHTML = ((d && d.categories) || []).map(function (c) { return '<option value="' + esc(c) + '">'; }).join('');
      }).catch(function () {});

      host.querySelector('#kb-back').addEventListener('click', loadList);
      host.querySelector('#kb-cancel').addEventListener('click', function () {
        if (existing) openArticle(existing.id); else loadList();
      });

      var save = host.querySelector('#kb-save');
      var msg = host.querySelector('#kb-msg');
      save.addEventListener('click', function () {
        var payload = {
          title: host.querySelector('#kb-title').value.trim(),
          body: host.querySelector('#kb-text').value.trim(),
          category: host.querySelector('#kb-cat-in').value.trim(),
          tags: host.querySelector('#kb-tags').value.trim(),
        };
        if (payload.title.length < 3) { msg.textContent = 'Give it a title.'; msg.style.color = '#B91C1C'; return; }
        if (payload.body.length < 10) { msg.textContent = 'Write a little more.'; msg.style.color = '#B91C1C'; return; }
        save.disabled = true;
        msg.textContent = 'Saving…'; msg.style.color = '#64748B';
        var req = existing ? Api.put('/kb/' + existing.id, payload) : Api.post('/kb', payload);
        req.then(function () {
          toast('📚', existing ? 'Article updated' : 'Article published', payload.title, '#16A34A');
          loadList();
        }).catch(function (e) {
          save.disabled = false;
          msg.textContent = (e && e.message) || 'Could not save it.';
          msg.style.color = '#B91C1C';
        });
      });
    }

    // ── wiring ──────────────────────────────────────────────────────────
    newBtn.addEventListener('click', function () { openEditor(null); });

    var timer = null;
    qEl.addEventListener('input', function () {
      // Search as they type, but not on every keystroke.
      if (timer) clearTimeout(timer);
      timer = setTimeout(function () { state.q = qEl.value.trim(); loadList(); }, 280);
    });
    catEl.addEventListener('change', function () { state.category = catEl.value; loadList(); });

    loadList();
  }

  // Every role, by design: the knowledge is the agency's, not an admin's.
  ['guardian', 'educator', 'home_visitor', 'centre_director', 'agency_admin',
   'platform_admin', 'auditor', 'sales_rep'].forEach(function (r) {
    Shell.registerScreen(r + ':knowledge-base', render);
  });

  KT.KnowledgeBase = { render: render };
})(window);
