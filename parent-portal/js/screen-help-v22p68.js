/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p68 — Help & Guide (rebuilt)
   • Collapsible accordion categories (only one expanded at a time)
   • Live search across titles + body
   • Article TOC right rail (jump links from ##headings)
   • Mobile drawer (sidebar collapses to category dropdown <640px)
   • Print article button + "Was this helpful?" feedback
   • Quick category pills + Featured / Popular / Recent panels on home
   • Keeps AI Ask widget + floating button from previous build
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';
  const { Api, Dom, Shell } = window.KT;

  let state = {
    categorized: null,
    expandedCategory: null,
    currentSlug: null,
    currentArticle: null,
    featured: [],
    popular: [],
    recent: JSON.parse(localStorage.getItem('kt_help_recent') || '[]'),
    searchTerm: '',
  };

  async function renderHelp(main) {
    Dom.clear(main);
    injectStyles();

    const wrap = Dom.el('div', { class: 'kt-help-wrap' });
    main.appendChild(wrap);

    // Hero header
    const hero = Dom.el('div', { class: 'kt-help-hero' });
    const left = Dom.el('div', {});
    left.appendChild(Dom.el('h1', { class: 'kt-help-title' }, 'Help & Guide'));
    left.appendChild(Dom.el('p', { class: 'kt-help-sub' }, 'Find answers fast. 30+ articles, AI search, contextual hints.'));
    hero.appendChild(left);

    const askBtn = Dom.el('button', { class: 'kt-help-ask-btn' }, ['✨', Dom.el('span', {}, 'Ask AI')]);
    askBtn.addEventListener('click', showAskModal);
    hero.appendChild(askBtn);
    wrap.appendChild(hero);

    /* v22p70: search lives inside sidebar — declare input here, attach inside renderSidebar */
    const searchInput = Dom.el('input', {
      class: 'kt-help-search',
      type: 'search',
      placeholder: '\u{1F50D}  Search topics\u{2026}',
    });
    searchInput.addEventListener('input', (e) => {
      state.searchTerm = e.target.value.trim().toLowerCase();
      renderSidebar();
      if (state.searchTerm.length >= 2) {
        renderSearchResults();
      } else if (state.currentSlug) {
        loadArticle(state.currentSlug);
      } else {
        renderHomePanels();
      }
    });

    // Mobile category select (hidden on desktop)
    const mobileSelect = Dom.el('select', { class: 'kt-help-mobile-select' });
    mobileSelect.addEventListener('change', (e) => {
      const cat = e.target.value;
      if (cat) {
        state.expandedCategory = cat;
        const firstArticle = (state.categorized[cat] || [])[0];
        if (firstArticle) loadArticle(firstArticle.slug);
      }
    });
    wrap.appendChild(mobileSelect);

    // Layout: sidebar + content + TOC
    const layout = Dom.el('div', { class: 'kt-help-layout' });
    wrap.appendChild(layout);

    const sidebar = Dom.el('aside', { class: 'kt-help-sidebar' });
    layout.appendChild(sidebar);

    const content = Dom.el('main', { class: 'kt-help-content' });
    layout.appendChild(content);

    const toc = Dom.el('aside', { class: 'kt-help-toc' });
    layout.appendChild(toc);

    content.appendChild(Dom.el('p', { class: 'kt-help-loading' }, 'Loading articles…'));

    try {
      const [data, extra] = await Promise.all([
        Api.get('/help'),
        Api.get('/help/dashboard').catch(() => ({ featured: [], popular: [] })),
      ]);
      state.categorized = data.categorized || {};
      state.featured = extra.featured || [];
      state.popular = extra.popular || [];

      renderSidebar();
      renderMobileSelect();

      // Initial view: hash → article OR home panels
      const hash = (location.hash.split('/')[1] || '').trim();
      if (hash) loadArticle(hash);
      else renderHomePanels();
    } catch (e) {
      Dom.clear(content);
      content.appendChild(Dom.el('p', { class: 'kt-help-error' }, 'Could not load help: ' + e.message));
    }

    function renderSidebar() {
      Dom.clear(sidebar);
      /* v22p70: search lives at top of sidebar */
      const searchBox = Dom.el('div', { class: 'kt-help-search-box' });
      searchBox.appendChild(searchInput);
      sidebar.appendChild(searchBox);
      const allCats = Object.keys(state.categorized).sort();

      // Category pills (quick jump)
      const pills = Dom.el('div', { class: 'kt-help-pills' });
      allCats.forEach(cat => {
        const pill = Dom.el('button', {
          class: 'kt-help-pill' + (cat === state.expandedCategory ? ' is-active' : ''),
        }, cat);
        pill.addEventListener('click', () => {
          state.expandedCategory = (state.expandedCategory === cat) ? null : cat;
          renderSidebar();
        });
        pills.appendChild(pill);
      });
      sidebar.appendChild(pills);

      // Filter by search
      const filtered = {};
      const term = state.searchTerm;
      Object.entries(state.categorized).forEach(([cat, arts]) => {
        const matches = term
          ? arts.filter(a => a.title.toLowerCase().includes(term) || cat.toLowerCase().includes(term))
          : arts;
        if (matches.length) filtered[cat] = matches;
      });

      // Accordion categories
      Object.entries(filtered).forEach(([cat, arts]) => {
        const isExpanded = term ? true : (cat === state.expandedCategory);
        const block = Dom.el('div', { class: 'kt-help-accordion' + (isExpanded ? ' is-open' : '') });

        const head = Dom.el('button', { class: 'kt-help-accordion-head' });
        head.appendChild(Dom.el('span', { class: 'kt-help-cat-name' }, cat));
        const meta = Dom.el('span', { class: 'kt-help-cat-meta' });
        meta.appendChild(Dom.el('span', { class: 'kt-help-cat-count' }, String(arts.length)));
        meta.appendChild(Dom.el('span', { class: 'kt-help-chev' }, isExpanded ? '▾' : '▸'));
        head.appendChild(meta);
        head.addEventListener('click', () => {
          state.expandedCategory = (state.expandedCategory === cat) ? null : cat;
          renderSidebar();
        });
        block.appendChild(head);

        if (isExpanded) {
          const body = Dom.el('div', { class: 'kt-help-accordion-body' });
          arts.forEach(a => {
            const link = Dom.el('a', {
              href: '#help/' + a.slug,
              class: 'kt-help-link' + (a.slug === state.currentSlug ? ' is-active' : ''),
            }, a.title);
            link.addEventListener('click', (ev) => {
              ev.preventDefault();
              loadArticle(a.slug);
            });
            body.appendChild(link);
          });
          block.appendChild(body);
        }
        sidebar.appendChild(block);
      });

      if (term && Object.keys(filtered).length === 0) {
        sidebar.appendChild(Dom.el('p', { class: 'kt-help-empty' }, 'No matches for "' + term + '"'));
      }
    }

    function renderMobileSelect() {
      Dom.clear(mobileSelect);
      mobileSelect.appendChild(Dom.el('option', { value: '' }, 'Pick a category…'));
      Object.keys(state.categorized).sort().forEach(cat => {
        const opt = Dom.el('option', { value: cat }, cat + ' (' + state.categorized[cat].length + ')');
        if (cat === state.expandedCategory) opt.selected = true;
        mobileSelect.appendChild(opt);
      });
    }

    function renderHomePanels() {
      Dom.clear(content);
      Dom.clear(toc);
      state.currentSlug = null;
      state.currentArticle = null;

      const home = Dom.el('div', { class: 'kt-help-home' });
      content.appendChild(home);

      home.appendChild(Dom.el('h2', { class: 'kt-help-home-h' }, '👋  Where to start'));
      home.appendChild(Dom.el('p', { class: 'kt-help-home-sub' }, 'Browse by category in the left sidebar, search above, or jump straight in:'));

      if (state.featured.length) {
        home.appendChild(panelOfCards('⭐ Featured', state.featured));
      }
      if (state.recent.length) {
        home.appendChild(panelOfCards('🕘 Recently viewed', state.recent.slice(0, 6)));
      }
      if (state.popular.length) {
        home.appendChild(panelOfCards('🔥 Popular this month', state.popular));
      }

      // Big quick-start card
      const qs = Dom.el('div', { class: 'kt-help-quickstart' });
      qs.appendChild(Dom.el('h3', { style: 'margin: 0 0 8px;' }, 'New to KiddieTrac?'));
      qs.appendChild(Dom.el('p', { style: 'margin: 0 0 12px; opacity: 0.9;' }, 'Read the master roles & responsibilities article to understand who can do what.'));
      const qsBtn = Dom.el('button', { class: 'kt-help-quickstart-btn' }, 'Read roles & responsibilities  →');
      qsBtn.addEventListener('click', () => loadArticle('roles-and-responsibilities'));
      qs.appendChild(qsBtn);
      home.appendChild(qs);
    }

    function panelOfCards(title, articles) {
      const panel = Dom.el('div', { class: 'kt-help-panel' });
      panel.appendChild(Dom.el('h3', { class: 'kt-help-panel-h' }, title));
      const grid = Dom.el('div', { class: 'kt-help-grid' });
      articles.forEach(a => {
        const slug = a.slug || a;
        const ttl = a.title || slug;
        const cat = a.category || '';
        const card = Dom.el('button', { class: 'kt-help-card' });
        if (cat) card.appendChild(Dom.el('div', { class: 'kt-help-card-cat' }, cat));
        card.appendChild(Dom.el('div', { class: 'kt-help-card-ttl' }, ttl));
        card.addEventListener('click', () => loadArticle(slug));
        grid.appendChild(card);
      });
      panel.appendChild(grid);
      return panel;
    }

    function renderSearchResults() {
      Dom.clear(content);
      Dom.clear(toc);
      const term = state.searchTerm;
      const matches = [];
      Object.entries(state.categorized).forEach(([cat, arts]) => {
        arts.forEach(a => {
          if (a.title.toLowerCase().includes(term) || cat.toLowerCase().includes(term)) {
            matches.push({ ...a, category: cat });
          }
        });
      });
      content.appendChild(Dom.el('div', { class: 'kt-help-search-head' },
        matches.length + ' result' + (matches.length === 1 ? '' : 's') + ' for "' + term + '"'));
      if (!matches.length) {
        const ask = Dom.el('button', { class: 'kt-help-ask-fallback' }, '✨ Ask the AI about "' + term + '" instead');
        ask.addEventListener('click', () => {
          showAskModal(term);
        });
        content.appendChild(ask);
        return;
      }
      const list = Dom.el('div', { class: 'kt-help-results' });
      matches.forEach(a => {
        const row = Dom.el('button', { class: 'kt-help-result' });
        row.appendChild(Dom.el('div', { class: 'kt-help-result-cat' }, a.category));
        row.appendChild(Dom.el('div', { class: 'kt-help-result-ttl' }, a.title));
        row.addEventListener('click', () => {
          // Clear search input
          searchInput.value = '';
          state.searchTerm = '';
          loadArticle(a.slug);
        });
        list.appendChild(row);
      });
      content.appendChild(list);
    }

    async function loadArticle(slug) {
      state.currentSlug = slug;
      Dom.clear(content);
      Dom.clear(toc);
      content.appendChild(Dom.el('p', { class: 'kt-help-loading' }, 'Loading article…'));

      try {
        const data = await Api.get('/help/' + slug);
        state.currentArticle = data.article;
        renderArticle(data.article);
        renderToc(data.article);
        // Track view
        Api.post('/help/' + slug + '/view').catch(() => {});
        // Save to recent
        const rec = state.recent.filter(r => (r.slug || r) !== slug);
        rec.unshift({ slug, title: data.article.title, category: data.article.category });
        state.recent = rec.slice(0, 10);
        localStorage.setItem('kt_help_recent', JSON.stringify(state.recent));
        // Update sidebar active state
        renderSidebar();
        // Update URL
        window.history.replaceState(null, '', '#help/' + slug);
        // Find category and expand it
        Object.entries(state.categorized).forEach(([cat, arts]) => {
          if (arts.some(a => a.slug === slug)) state.expandedCategory = cat;
        });
      } catch (e) {
        Dom.clear(content);
        content.appendChild(Dom.el('p', { class: 'kt-help-error' }, 'Could not load article: ' + e.message));
      }
    }

    function renderArticle(article) {
      Dom.clear(content);

      // Breadcrumb + actions row
      const top = Dom.el('div', { class: 'kt-help-article-top' });
      const crumb = Dom.el('div', { class: 'kt-help-crumb' });
      const homeLink = Dom.el('a', { href: '#help', class: 'kt-help-crumb-link' }, 'Help');
      homeLink.addEventListener('click', (ev) => { ev.preventDefault(); renderHomePanels(); window.history.replaceState(null, '', '#help'); });
      crumb.appendChild(homeLink);
      crumb.appendChild(Dom.el('span', { class: 'kt-help-crumb-sep' }, '›'));
      crumb.appendChild(Dom.el('span', { class: 'kt-help-crumb-cat' }, article.category));
      top.appendChild(crumb);

      const actions = Dom.el('div', { class: 'kt-help-article-actions' });
      const printBtn = Dom.el('button', { class: 'kt-help-action-btn', title: 'Print article' }, '🖨️');
      printBtn.addEventListener('click', () => printArticle(article));
      actions.appendChild(printBtn);

      const copyBtn = Dom.el('button', { class: 'kt-help-action-btn', title: 'Copy link' }, '🔗');
      copyBtn.addEventListener('click', () => {
        navigator.clipboard.writeText(location.origin + location.pathname + '#help/' + article.slug);
        copyBtn.textContent = '✓';
        setTimeout(() => copyBtn.textContent = '🔗', 1200);
      });
      actions.appendChild(copyBtn);
      top.appendChild(actions);
      content.appendChild(top);

      // Title
      content.appendChild(Dom.el('h1', { class: 'kt-help-article-title' }, article.title));

      // Markdown body (with ## headings → anchors for TOC)
      const wrapper = Dom.el('div', { class: 'kt-help-markdown' });
      wrapper.innerHTML = renderMarkdown(article.body);
      // Add IDs to h2/h3 for TOC anchors
      wrapper.querySelectorAll('h2, h3').forEach((h, i) => {
        h.id = 'h-' + slugify(h.textContent) + '-' + i;
      });
      content.appendChild(wrapper);

      // Feedback widget
      const feedback = renderFeedbackWidget(article);
      content.appendChild(feedback);
    }

    function renderToc(article) {
      Dom.clear(toc);
      const headings = content.querySelectorAll('.kt-help-markdown h2, .kt-help-markdown h3');
      if (!headings.length) return;

      toc.appendChild(Dom.el('div', { class: 'kt-help-toc-title' }, 'On this page'));
      const list = Dom.el('div', { class: 'kt-help-toc-list' });
      headings.forEach(h => {
        const link = Dom.el('a', {
          href: '#' + h.id,
          class: 'kt-help-toc-link kt-help-toc-' + h.tagName.toLowerCase(),
        }, h.textContent);
        link.addEventListener('click', (ev) => {
          ev.preventDefault();
          h.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        list.appendChild(link);
      });
      toc.appendChild(list);
    }

    function renderFeedbackWidget(article) {
      const wrap = Dom.el('div', { class: 'kt-help-feedback' });
      wrap.appendChild(Dom.el('div', { class: 'kt-help-feedback-q' }, 'Was this article helpful?'));
      const btns = Dom.el('div', { class: 'kt-help-feedback-btns' });
      const yes = Dom.el('button', { class: 'kt-help-fb-btn' }, '👍  Yes');
      const no = Dom.el('button', { class: 'kt-help-fb-btn' }, '👎  No');
      [yes, no].forEach((b, i) => {
        b.addEventListener('click', async () => {
          const helpful = (i === 0);
          yes.disabled = true; no.disabled = true;
          try {
            await Api.post('/help/' + article.slug + '/feedback', { helpful });
            Dom.clear(wrap);
            wrap.appendChild(Dom.el('div', { class: 'kt-help-feedback-thanks' },
              '🙏  Thanks for the feedback!' + (helpful ? '' : ' Try ✨ Ask AI for a follow-up.')));
          } catch (e) {
            yes.disabled = false; no.disabled = false;
          }
        });
        btns.appendChild(b);
      });
      wrap.appendChild(btns);
      return wrap;
    }

    function printArticle(article) {
      const w = window.open('', 'print', 'width=900,height=700');
      const md = renderMarkdown(article.body);
      w.document.write(`<!DOCTYPE html><html><head><title>${article.title}</title>
        <style>
          body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; color: #1F2937; line-height: 1.6; }
          h1 { color: #1F6080; border-bottom: 2px solid #1F6080; padding-bottom: 8px; }
          h2 { color: #1F6080; margin-top: 28px; }
          h3 { color: #1F2937; }
          code { background: #F3F4F6; padding: 2px 6px; border-radius: 4px; font-size: 0.9em; }
          table { border-collapse: collapse; margin: 16px 0; }
          th, td { border: 1px solid #E5E7EB; padding: 8px 12px; text-align: left; }
          th { background: #F9FAFB; }
          .meta { color: #6B7280; font-size: 12px; margin-bottom: 24px; }
          @media print { body { max-width: none; padding: 0; } }
        </style>
        </head><body>
        <div class="meta">KiddieTrac help guide · ${article.category} · ${article.slug}</div>
        <h1>${article.title}</h1>
        ${md}
        </body></html>`);
      w.document.close();
      setTimeout(() => w.print(), 300);
    }
  }

  // ─── Markdown renderer (same as v6) ─────────────────────────

  function slugify(t) {
    return t.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '').slice(0, 50);
  }

  function renderMarkdown(md) {
    let html = md;
    html = html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    html = html.replace(/(\|.+\|\n\|[-:| ]+\|\n(?:\|.+\|\n?)+)/g, (match) => {
      const lines = match.trim().split('\n');
      const header = lines[0].split('|').map(c => c.trim()).filter(c => c);
      const rows = lines.slice(2).map(line => line.split('|').map(c => c.trim()).filter(c => c));
      let t = '<table><thead><tr>';
      header.forEach(h => t += `<th>${h}</th>`);
      t += '</tr></thead><tbody>';
      rows.forEach(row => {
        t += '<tr>';
        row.forEach(c => t += `<td>${c}</td>`);
        t += '</tr>';
      });
      t += '</tbody></table>';
      return t;
    });

    html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
    html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');
    html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    html = html.replace(/\[\[([^\]]+)\]\]/g, '<a href="#help/$1" class="kt-help-wiki">$1</a>');
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>(?:\n<li>.*<\/li>)*)/g, '<ul>$1</ul>');
    html = html.replace(/^\d+\. (.+)$/gm, '<oli>$1</oli>');
    html = html.replace(/(<oli>.*<\/oli>(?:\n<oli>.*<\/oli>)*)/g, '<ol>$1</ol>');
    html = html.replace(/<oli>/g, '<li>').replace(/<\/oli>/g, '</li>');
    html = html.split(/\n\n+/).map(block => {
      if (block.match(/^<(h\d|ul|ol|table|blockquote)/)) return block;
      if (block.trim() === '') return '';
      return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
    }).join('\n');
    return html;
  }

  // ─── AI Ask Modal (compact) ─────────────────────────────────

  function showAskModal(prefill) {
    const body = Dom.el('div', {});
    body.appendChild(Dom.el('p', { class: 'kt-ask-intro' }, 'Ask in plain English. The AI reads every help article + your role.'));
    const input = Dom.el('textarea', { class: 'kt-ask-input', placeholder: 'e.g. "How do I refund a payment?"' });
    if (prefill) input.value = prefill;
    body.appendChild(input);
    setTimeout(() => input.focus(), 100);
    const askBtn = Dom.el('button', { class: 'kt-ask-go' }, '✨ Ask');
    body.appendChild(askBtn);
    const answerArea = Dom.el('div', { class: 'kt-ask-answer-area' });
    body.appendChild(answerArea);
    askBtn.addEventListener('click', async () => {
      const q = input.value.trim();
      if (!q) return;
      askBtn.disabled = true; askBtn.textContent = 'Thinking...';
      Dom.clear(answerArea);
      answerArea.appendChild(Dom.el('div', { class: 'kt-ask-pending' }, 'Searching the help docs...'));
      try {
        const res = await Api.post('/help/ask', { question: q });
        Dom.clear(answerArea);
        const box = Dom.el('div', { class: 'kt-ask-answer' });
        box.appendChild(Dom.el('div', { class: 'kt-ask-answer-l' }, '✨ ANSWER'));
        const b = Dom.el('div', { class: 'kt-ask-answer-body' });
        b.textContent = res.answer || '(empty response)';
        box.appendChild(b);
        if (res.sources && res.sources.length) {
          const src = Dom.el('div', { class: 'kt-ask-sources' });
          src.appendChild(Dom.el('div', { class: 'kt-ask-sources-l' }, 'Sources:'));
          res.sources.forEach(s => {
            const a = Dom.el('a', { href: '#help/' + s, class: 'kt-ask-source-link' }, s);
            a.addEventListener('click', () => Shell.Modal.close());
            src.appendChild(a);
          });
          box.appendChild(src);
        }
        answerArea.appendChild(box);
        askBtn.disabled = false; askBtn.textContent = '✨ Ask again';
      } catch (e) {
        Dom.clear(answerArea);
        answerArea.appendChild(Dom.el('div', { class: 'kt-ask-error' }, 'Could not get an answer. ' + (e.message || 'Try again later.')));
        askBtn.disabled = false; askBtn.textContent = '✨ Try again';
      }
    });
    Shell.Modal.open({ title: 'Ask a question', body: body, large: true });
    if (prefill) setTimeout(() => askBtn.click(), 100);
  }

  // ─── Floating help button ──────────────────────────────────

  function injectFloatingHelpButton() {
    if (document.getElementById('kt-floating-help')) return;
    const btn = document.createElement('button');
    btn.id = 'kt-floating-help';
    btn.title = 'Ask a question';
    btn.innerHTML = '✨';
    btn.style.cssText = `
      position: fixed; bottom: 24px; right: 24px; z-index: 9000;
      width: 56px; height: 56px; border-radius: 28px;
      background: linear-gradient(135deg, #1F6080 0%, #2c7894 100%);
      color: white; border: none; font-size: 24px; cursor: pointer;
      box-shadow: 0 8px 20px rgba(31, 96, 128, 0.45);
      transition: transform 0.15s;
    `;
    btn.addEventListener('mouseenter', () => btn.style.transform = 'scale(1.08)');
    btn.addEventListener('mouseleave', () => btn.style.transform = 'scale(1)');
    btn.addEventListener('click', () => showAskModal());
    document.body.appendChild(btn);
  }

  function injectStyles() {
    if (document.getElementById('kt-help-v22p68-styles')) return;
    const style = document.createElement('style');
    style.id = 'kt-help-v22p68-styles';
    style.textContent = `
      .kt-help-wrap { max-width: 1800px; margin: 0 auto; padding: 24px; }
      .kt-help-hero { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; padding: 24px 28px; background: linear-gradient(135deg, #1F6080 0%, #2D7BA8 60%, #4A90B8 100%); color: white; border-radius: 20px; box-shadow: 0 6px 24px rgba(31, 96, 128, 0.18); }
      .kt-help-title { margin: 0 0 6px; font-size: 30px; font-weight: 800; }
      .kt-help-sub { margin: 0; opacity: 0.92; font-size: 14px; }
      .kt-help-ask-btn { background: rgba(255,255,255,0.18); backdrop-filter: blur(8px); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 12px 22px; border-radius: 28px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 15px; transition: background 0.15s; }
      .kt-help-ask-btn:hover { background: rgba(255,255,255,0.28); }

      /* v22p70: search now lives inside sidebar */
      .kt-help-search-box { margin-bottom: 14px; padding-bottom: 14px; border-bottom: 1px solid #E5E7EB; }
      .kt-help-search { width: 100%; padding: 10px 14px; border: 1.5px solid #E5E7EB; border-radius: 10px; font-size: 14px; font-family: inherit; background: white; transition: border-color 0.15s, box-shadow 0.15s; box-sizing: border-box; }
      .kt-help-search:focus { outline: none; border-color: #1F6080; box-shadow: 0 0 0 3px rgba(31, 96, 128, 0.12); }

      .kt-help-mobile-select { display: none; width: 100%; padding: 12px; border: 2px solid #E5E7EB; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }

      .kt-help-layout { display: grid; grid-template-columns: 280px 1fr 220px; gap: 24px; align-items: flex-start; }

      .kt-help-sidebar { background: #F9FAFB; padding: 16px; border-radius: 14px; position: sticky; top: 150px; max-height: calc(100vh - 180px); overflow-y: auto; }
      .kt-help-pills { display: flex; flex-wrap: wrap; gap: 6px; padding-bottom: 12px; border-bottom: 1px solid #E5E7EB; margin-bottom: 12px; }
      .kt-help-pill { background: white; border: 1px solid #E5E7EB; border-radius: 16px; padding: 6px 12px; font-size: 11px; font-weight: 600; cursor: pointer; color: #4B5563; transition: all 0.12s; }
      .kt-help-pill:hover { background: #F3F4F6; }
      .kt-help-pill.is-active { background: #1F6080; color: white; border-color: #1F6080; }

      .kt-help-accordion { margin-bottom: 6px; }
      .kt-help-accordion-head { width: 100%; display: flex; justify-content: space-between; align-items: center; background: transparent; border: none; padding: 10px 8px; cursor: pointer; border-radius: 8px; font-family: inherit; color: #1F2937; transition: background 0.12s; }
      .kt-help-accordion-head:hover { background: #F3F4F6; }
      .kt-help-cat-name { font-weight: 700; font-size: 12px; letter-spacing: 0.5px; text-transform: uppercase; color: #4B5563; }
      .kt-help-cat-meta { display: flex; align-items: center; gap: 6px; }
      .kt-help-cat-count { background: #E5E7EB; color: #4B5563; padding: 1px 7px; border-radius: 8px; font-size: 11px; font-weight: 700; }
      .kt-help-chev { font-size: 14px; color: #6B7280; }
      .kt-help-accordion-body { padding: 4px 0 8px 8px; }
      .kt-help-link { display: block; padding: 7px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; color: #1F2937; margin-bottom: 2px; transition: all 0.12s; }
      .kt-help-link:hover { background: white; color: #1F6080; }
      .kt-help-link.is-active { background: white; color: #1F6080; font-weight: 700; box-shadow: 0 1px 4px rgba(31, 96, 128, 0.15); }
      .kt-help-empty { color: #6B7280; padding: 16px 8px; font-size: 13px; }

      .kt-help-content { background: white; padding: 36px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); min-height: 60vh; }
      .kt-help-loading { color: #6B7280; }
      .kt-help-error { color: #c0392b; }

      .kt-help-article-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
      .kt-help-crumb { font-size: 13px; color: #6B7280; }
      .kt-help-crumb-link { color: #1F6080; text-decoration: none; }
      .kt-help-crumb-link:hover { text-decoration: underline; }
      .kt-help-crumb-sep { margin: 0 8px; color: #9CA3AF; }
      .kt-help-crumb-cat { font-weight: 600; color: #4B5563; }
      .kt-help-article-actions { display: flex; gap: 8px; }
      .kt-help-action-btn { background: #F3F4F6; border: none; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; font-size: 16px; transition: background 0.12s; }
      .kt-help-action-btn:hover { background: #E5E7EB; }

      .kt-help-article-title { margin: 0 0 24px; font-size: 32px; font-weight: 800; color: #111827; line-height: 1.2; }

      .kt-help-markdown { font-size: 15px; line-height: 1.7; color: #1F2937; }
      .kt-help-markdown h1 { font-size: 24px; margin: 28px 0 12px; color: #111827; }
      .kt-help-markdown h2 { font-size: 20px; margin: 28px 0 12px; color: #1F6080; padding-bottom: 6px; border-bottom: 2px solid #E5E7EB; }
      .kt-help-markdown h3 { font-size: 17px; margin: 24px 0 8px; color: #1F2937; font-weight: 700; }
      .kt-help-markdown p { margin: 0 0 14px; }
      .kt-help-markdown ul, .kt-help-markdown ol { padding-left: 24px; margin: 0 0 14px; }
      .kt-help-markdown li { margin: 6px 0; }
      .kt-help-markdown code { background: #F3F4F6; padding: 2px 8px; border-radius: 5px; font-size: 13px; font-family: Menlo, Consolas, monospace; color: #1F6080; }
      .kt-help-markdown blockquote { border-left: 3px solid #8EC73C; padding: 8px 16px; margin: 14px 0; background: #F9FAFB; color: #4B5563; font-style: italic; border-radius: 0 8px 8px 0; }
      .kt-help-markdown table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px; }
      .kt-help-markdown th { background: #F9FAFB; padding: 10px 14px; text-align: left; border-bottom: 2px solid #E5E7EB; font-weight: 700; color: #1F2937; }
      .kt-help-markdown td { padding: 10px 14px; border-bottom: 1px solid #F3F4F6; vertical-align: top; }
      .kt-help-markdown a { color: #1F6080; text-decoration: underline; }
      .kt-help-markdown a.kt-help-wiki { background: rgba(31, 96, 128, 0.08); padding: 1px 6px; border-radius: 4px; text-decoration: none; font-weight: 600; }
      .kt-help-markdown a.kt-help-wiki:hover { background: rgba(31, 96, 128, 0.18); }
      .kt-help-markdown strong { font-weight: 700; }

      .kt-help-toc { background: white; padding: 20px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); position: sticky; top: 150px; max-height: calc(100vh - 180px); overflow-y: auto; }
      .kt-help-toc-title { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #6B7280; margin-bottom: 12px; }
      .kt-help-toc-list { display: flex; flex-direction: column; gap: 4px; }
      .kt-help-toc-link { display: block; padding: 6px 8px; font-size: 13px; color: #4B5563; text-decoration: none; border-left: 2px solid transparent; transition: all 0.12s; line-height: 1.4; }
      .kt-help-toc-link:hover { color: #1F6080; border-left-color: #1F6080; background: #F9FAFB; }
      .kt-help-toc-h3 { padding-left: 20px; font-size: 12px; }

      .kt-help-feedback { margin-top: 40px; padding: 20px; background: #F9FAFB; border-radius: 12px; text-align: center; }
      .kt-help-feedback-q { font-weight: 600; margin-bottom: 12px; color: #1F2937; }
      .kt-help-feedback-btns { display: flex; gap: 12px; justify-content: center; }
      .kt-help-fb-btn { background: white; border: 1px solid #E5E7EB; padding: 8px 20px; border-radius: 24px; font-size: 14px; cursor: pointer; transition: all 0.12s; }
      .kt-help-fb-btn:hover:not(:disabled) { border-color: #1F6080; color: #1F6080; }
      .kt-help-fb-btn:disabled { opacity: 0.5; cursor: not-allowed; }
      .kt-help-feedback-thanks { color: #1F6080; font-weight: 600; }

      .kt-help-home { display: flex; flex-direction: column; gap: 32px; }
      .kt-help-home-h { margin: 0 0 4px; font-size: 26px; color: #111827; }
      .kt-help-home-sub { margin: 0 0 12px; color: #6B7280; }
      .kt-help-panel-h { font-size: 14px; font-weight: 700; letter-spacing: 0.5px; color: #4B5563; margin: 0 0 12px; text-transform: uppercase; }
      .kt-help-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
      .kt-help-card { background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 16px; cursor: pointer; text-align: left; transition: all 0.15s; }
      .kt-help-card:hover { border-color: #1F6080; box-shadow: 0 4px 12px rgba(31, 96, 128, 0.12); transform: translateY(-2px); }
      .kt-help-card-cat { font-size: 10px; font-weight: 700; letter-spacing: 0.5px; color: #8EC73C; text-transform: uppercase; margin-bottom: 4px; }
      .kt-help-card-ttl { font-size: 14px; font-weight: 600; color: #1F2937; line-height: 1.3; }

      .kt-help-quickstart { background: linear-gradient(135deg, #1F6080 0%, #2D7BA8 100%); color: white; padding: 28px; border-radius: 16px; }
      .kt-help-quickstart-btn { background: white; color: #1F6080; border: none; padding: 12px 22px; border-radius: 24px; font-weight: 700; cursor: pointer; font-size: 14px; }

      .kt-help-search-head { font-size: 14px; color: #6B7280; margin-bottom: 16px; }
      .kt-help-results { display: flex; flex-direction: column; gap: 8px; }
      .kt-help-result { background: white; border: 1px solid #E5E7EB; border-radius: 10px; padding: 14px 18px; cursor: pointer; text-align: left; transition: all 0.12s; }
      .kt-help-result:hover { border-color: #1F6080; background: #F9FAFB; }
      .kt-help-result-cat { font-size: 10px; font-weight: 700; color: #8EC73C; letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 2px; }
      .kt-help-result-ttl { font-size: 14px; font-weight: 600; color: #1F2937; }
      .kt-help-ask-fallback { background: linear-gradient(135deg, #1F6080, #2D7BA8); color: white; border: none; padding: 16px 24px; border-radius: 14px; font-weight: 700; font-size: 14px; cursor: pointer; width: 100%; }

      .kt-ask-intro { color: #6B7280; margin: 0 0 14px; font-size: 14px; }
      .kt-ask-input { width: 100%; min-height: 80px; padding: 12px; border: 1px solid #D1D5DB; border-radius: 10px; font-family: inherit; font-size: 14px; resize: vertical; box-sizing: border-box; }
      .kt-ask-input:focus { outline: none; border-color: #1F6080; }
      .kt-ask-go { background: #1F6080; color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; margin-top: 12px; width: 100%; font-size: 15px; }
      .kt-ask-answer-area { margin-top: 16px; }
      .kt-ask-pending { background: #F9FAFB; padding: 14px; border-radius: 10px; color: #6B7280; }
      .kt-ask-answer { background: linear-gradient(135deg, #1F6080, #2D7BA8); color: white; padding: 18px; border-radius: 12px; }
      .kt-ask-answer-l { font-size: 11px; font-weight: 700; letter-spacing: 1px; opacity: 0.85; margin-bottom: 8px; }
      .kt-ask-answer-body { font-size: 14px; line-height: 1.6; white-space: pre-wrap; }
      .kt-ask-sources { margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.2); }
      .kt-ask-sources-l { font-size: 11px; opacity: 0.85; margin-bottom: 6px; }
      .kt-ask-source-link { color: white; text-decoration: underline; margin-right: 12px; font-size: 13px; }
      .kt-ask-error { background: #fff5f5; color: #c53030; padding: 14px; border-radius: 10px; font-size: 14px; }

      /* TABLET — TOC hides */
      @media (max-width: 1200px) {
        .kt-help-layout { grid-template-columns: 260px 1fr; }
        .kt-help-toc { display: none; }
      }

      /* MOBILE — sidebar hides, select shows */
      @media (max-width: 768px) {
        .kt-help-wrap { padding: 12px; }
        .kt-help-hero { flex-direction: column; align-items: flex-start; gap: 14px; padding: 18px; }
        .kt-help-title { font-size: 22px; }
        .kt-help-layout { grid-template-columns: 1fr; gap: 12px; }
        .kt-help-sidebar { display: none; }
        .kt-help-mobile-select { display: block; }
        .kt-help-content { padding: 20px; }
        .kt-help-article-title { font-size: 24px; }
        .kt-help-search-wrap { position: static; }
      }
    `;
    document.head.appendChild(style);
  }

  function checkHashAndRender() {
    if (location.hash.startsWith('#help')) {
      const main = document.querySelector('main, .kt-main, #app-main');
      if (main) renderHelp(main);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    setTimeout(injectFloatingHelpButton, 500);
    checkHashAndRender();
  });
  window.addEventListener('hashchange', checkHashAndRender);

  window.KT = window.KT || {};
  window.KT.renderHelp = renderHelp;
  window.KT.showAskModal = showAskModal;
  Shell.registerScreen("guardian:help", renderHelp);
  Shell.registerScreen("educator:help", renderHelp);
  Shell.registerScreen("centre_director:help", renderHelp);
  Shell.registerScreen("agency_admin:help", renderHelp);
  Shell.registerScreen("platform_admin:help", renderHelp);
})(window);
