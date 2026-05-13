/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v6 — Help Section
   Browse articles by category · AI-powered Ask widget
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const { Api, Dom, Shell } = window.KT;

  let state = {
    categorized: null,
    currentSlug: null,
    currentArticle: null,
  };

  async function renderHelp(main) {
    Dom.clear(main);

    const wrap = Dom.el('div', { style: 'max-width: 1100px; margin: 0 auto; padding: 24px;' });
    main.appendChild(wrap);

    // Header with title and Ask button
    const header = Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;' });
    header.appendChild(Dom.el('h1', { style: 'margin: 0;' }, 'Help'));
    const askBtn = Dom.el('button', {
      style: 'background: var(--brand-blue); color: white; border: none; padding: 10px 18px; border-radius: 24px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px;',
    }, ['✨', Dom.el('span', {}, 'Ask a question')]);
    askBtn.addEventListener('click', showAskModal);
    header.appendChild(askBtn);
    wrap.appendChild(header);

    // Two-column layout
    const container = Dom.el('div', { style: 'display: grid; grid-template-columns: 280px 1fr; gap: 32px; min-height: 60vh;' });
    wrap.appendChild(container);

    const sidebar = Dom.el('div', { style: 'background: var(--ink-50, #F9FAFB); padding: 16px; border-radius: 12px; height: fit-content; position: sticky; top: 24px;' });
    container.appendChild(sidebar);
    sidebar.appendChild(Dom.el('p', { style: 'color: var(--ink-500); margin: 0;' }, 'Loading topics…'));

    const content = Dom.el('div', { class: 'help-content', style: 'background: white; padding: 32px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);' });
    container.appendChild(content);

    try {
      const data = await Api.get('/help');
      state.categorized = data.categorized || {};
      renderSidebar(sidebar);
      // Render first article by default
      const firstCategory = Object.keys(state.categorized)[0];
      if (firstCategory) {
        const firstArticle = state.categorized[firstCategory][0];
        if (firstArticle) {
          await loadArticle(firstArticle.slug, content, sidebar);
        }
      }
    } catch (e) {
      Dom.clear(sidebar);
      sidebar.appendChild(Dom.el('p', { style: 'color: #c0392b;' }, 'Could not load help: ' + e.message));
    }
  }

  function renderSidebar(sidebar) {
    Dom.clear(sidebar);

    Object.entries(state.categorized).forEach(([category, articles]) => {
      const catHeader = Dom.el('div', { style: 'font-size: 11px; font-weight: 700; color: var(--ink-500); letter-spacing: 1px; margin: 16px 0 6px; text-transform: uppercase;' }, category);
      sidebar.appendChild(catHeader);

      articles.forEach(a => {
        const isActive = a.slug === state.currentSlug;
        const link = Dom.el('a', {
          href: '#help/' + a.slug,
          style: `display: block; padding: 8px 12px; border-radius: 6px; text-decoration: none; font-size: 14px; color: ${isActive ? 'var(--brand-blue)' : 'var(--ink-700)'}; font-weight: ${isActive ? '700' : '500'}; background: ${isActive ? 'white' : 'transparent'}; margin-bottom: 2px;`,
        }, a.title);
        link.addEventListener('click', async (ev) => {
          ev.preventDefault();
          const content = document.querySelector('.help-content');
          await loadArticle(a.slug, content, sidebar);
        });
        sidebar.appendChild(link);
      });
    });
  }

  async function loadArticle(slug, content, sidebar) {
    state.currentSlug = slug;
    Dom.clear(content);
    content.appendChild(Dom.el('p', { style: 'color: var(--ink-500);' }, 'Loading article…'));

    try {
      const data = await Api.get('/help/' + slug);
      state.currentArticle = data.article;
      renderArticle(state.currentArticle, content);
      renderSidebar(sidebar); // Refresh active state
      window.history.replaceState(null, '', '#help/' + slug);
    } catch (e) {
      Dom.clear(content);
      content.appendChild(Dom.el('p', { style: 'color: #c0392b;' }, 'Could not load article: ' + e.message));
    }
  }

  function renderArticle(article, container) {
    Dom.clear(container);

    // Category tag
    container.appendChild(Dom.el('div', { style: 'font-size: 12px; font-weight: 700; color: var(--brand-green); letter-spacing: 1px; margin-bottom: 8px; text-transform: uppercase;' }, article.category));

    // Render markdown body
    const html = renderMarkdown(article.body);
    const wrapper = Dom.el('div', { class: 'markdown-content', style: 'font-size: 15px; line-height: 1.6;' });
    wrapper.innerHTML = html;
    container.appendChild(wrapper);

    // Inject CSS for markdown
    injectMarkdownStyles();
  }

  // ─── Minimal Markdown Renderer ────────────────────────────────
  // Handles: h1-h3, paragraphs, bold/italic, lists, code, tables, links
  function renderMarkdown(md) {
    let html = md;

    // Escape HTML first
    html = html.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    // Tables (simple GFM-style with pipes)
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

    // Headers
    html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
    html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');

    // Blockquotes
    html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');

    // Code blocks (inline only — no fenced for simplicity)
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Bold + italic
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');

    // Links — [text](url)
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

    // Lists — unordered
    html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>(?:\n<li>.*<\/li>)*)/g, '<ul>$1</ul>');
    // Lists — ordered (1. 2. 3.)
    html = html.replace(/^\d+\. (.+)$/gm, '<oli>$1</oli>');
    html = html.replace(/(<oli>.*<\/oli>(?:\n<oli>.*<\/oli>)*)/g, '<ol>$1</ol>');
    html = html.replace(/<oli>/g, '<li>').replace(/<\/oli>/g, '</li>');

    // Paragraphs — wrap loose text in <p>
    html = html.split(/\n\n+/).map(block => {
      if (block.match(/^<(h\d|ul|ol|table|blockquote)/)) return block;
      if (block.trim() === '') return '';
      return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
    }).join('\n');

    return html;
  }

  function injectMarkdownStyles() {
    if (document.getElementById('markdown-styles-v6')) return;
    const style = document.createElement('style');
    style.id = 'markdown-styles-v6';
    style.textContent = `
      .markdown-content h1 { font-size: 28px; margin: 0 0 16px; color: var(--ink-900, #111827); line-height: 1.2; }
      .markdown-content h2 { font-size: 20px; margin: 24px 0 12px; color: var(--brand-blue, #1F6080); }
      .markdown-content h3 { font-size: 16px; margin: 20px 0 8px; color: var(--ink-800, #1F2937); font-weight: 700; }
      .markdown-content p { margin: 0 0 14px; }
      .markdown-content ul, .markdown-content ol { padding-left: 24px; margin: 0 0 14px; }
      .markdown-content li { margin: 4px 0; }
      .markdown-content code { background: var(--ink-100, #F3F4F6); padding: 2px 6px; border-radius: 4px; font-size: 13px; font-family: Menlo, Consolas, monospace; }
      .markdown-content blockquote { border-left: 3px solid var(--brand-green, #8EC73C); padding: 4px 16px; margin: 14px 0; background: var(--ink-50, #F9FAFB); color: var(--ink-700); font-style: italic; border-radius: 0 8px 8px 0; }
      .markdown-content table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px; }
      .markdown-content th { background: var(--ink-50, #F9FAFB); padding: 10px 12px; text-align: left; border-bottom: 2px solid var(--ink-200, #E5E7EB); font-weight: 700; }
      .markdown-content td { padding: 10px 12px; border-bottom: 1px solid var(--ink-100, #F3F4F6); vertical-align: top; }
      .markdown-content a { color: var(--brand-blue, #1F6080); text-decoration: underline; }
      .markdown-content strong { font-weight: 700; }
      .markdown-content em { font-style: italic; }
    `;
    document.head.appendChild(style);
  }

  // ─── AI Ask Modal ─────────────────────────────────────────────

  function showAskModal() {
    const body = Dom.el('div', {});
    const intro = Dom.el('p', { style: 'color: var(--ink-600); margin: 0 0 16px; font-size: 14px;' },
      'Ask in plain English. The AI knows everything in these help articles.');
    body.appendChild(intro);
    const examples = Dom.el('div', { style: 'background: var(--ink-50); padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 13px;' });
    examples.appendChild(Dom.el('div', { style: 'font-weight: 700; margin-bottom: 6px; color: var(--ink-600);' }, 'Examples:'));
    [
      'How do I add a child with a peanut allergy?',
      'Why does my room show BREACH?',
      'When will my daily digest be ready?',
    ].forEach(ex => {
      const link = Dom.el('a', { href: '#', style: 'display: block; color: var(--brand-blue); text-decoration: none; padding: 2px 0;' }, '"' + ex + '"');
      link.addEventListener('click', (ev) => { ev.preventDefault(); input.value = ex; input.focus(); });
      examples.appendChild(link);
    });
    body.appendChild(examples);
    const input = Dom.el('textarea', {
      placeholder: 'Type your question...',
      style: 'width: 100%; min-height: 80px; padding: 12px; border: 1px solid var(--ink-300); border-radius: 8px; font-family: inherit; font-size: 14px; resize: vertical; box-sizing: border-box;',
    });
    body.appendChild(input);
    setTimeout(() => input.focus(), 100);
    const askBtn = Dom.el('button', {
      style: 'background: var(--brand-blue); color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 12px; width: 100%; font-size: 15px;',
    }, '✨ Ask');
    body.appendChild(askBtn);
    const answerArea = Dom.el('div', { style: 'margin-top: 20px;' });
    body.appendChild(answerArea);
    askBtn.addEventListener('click', async () => {
      const q = input.value.trim();
      if (!q) return;
      askBtn.disabled = true;
      askBtn.textContent = 'Thinking...';
      Dom.clear(answerArea);
      answerArea.appendChild(Dom.el('div', {
        style: 'background: var(--ink-50); padding: 16px; border-radius: 8px; color: var(--ink-600); font-size: 14px;',
      }, 'Searching the help docs...'));
      try {
        const res = await Api.post('/help/ask', { question: q });
        Dom.clear(answerArea);
        const answerBox = Dom.el('div', { style: 'background: linear-gradient(135deg, #1F6080 0%, #2c7894 100%); color: white; padding: 20px; border-radius: 12px;' });
        answerBox.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 700; letter-spacing: 1px; opacity: 0.8; margin-bottom: 8px;' }, '✨ ANSWER'));
        const bodyDiv = Dom.el('div', { style: 'font-size: 14px; line-height: 1.6; white-space: pre-wrap;' });
        bodyDiv.textContent = res.answer || '(empty response)';
        answerBox.appendChild(bodyDiv);
        answerArea.appendChild(answerBox);
        askBtn.disabled = false;
        askBtn.textContent = '✨ Ask again';
      } catch (e) {
        Dom.clear(answerArea);
        answerArea.appendChild(Dom.el('div', {
          style: 'background: #fff5f5; color: #c53030; padding: 16px; border-radius: 8px; font-size: 14px;',
        }, 'Could not get an answer. ' + (e.message || 'Try again later.')));
        askBtn.disabled = false;
        askBtn.textContent = '✨ Try again';
      }
    });
    Shell.Modal.open({ title: 'Ask a question', body: body, large: true });
  }

  function spinner() {
    const s = Dom.el('div', { style: 'display: inline-block; width: 16px; height: 16px; border: 2px solid var(--ink-300); border-top-color: var(--brand-blue); border-radius: 50%; animation: spin 0.8s linear infinite;' });
    if (!document.getElementById('kt-spinner-style')) {
      const css = document.createElement('style');
      css.id = 'kt-spinner-style';
      css.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
      document.head.appendChild(css);
    }
    return s;
  }

  // ─── Floating help button (appears on every page) ──────────────

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
      box-shadow: 0 4px 12px rgba(31, 96, 128, 0.4);
      transition: transform 0.15s;
    `;
    btn.addEventListener('mouseenter', () => btn.style.transform = 'scale(1.08)');
    btn.addEventListener('mouseleave', () => btn.style.transform = 'scale(1)');
    btn.addEventListener('click', showAskModal);
    document.body.appendChild(btn);
  }

  // ─── Wire up navigation ────────────────────────────────────────

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

  // Expose
  window.KT = window.KT || {};
  window.KT.renderHelp = renderHelp;
  window.KT.showAskModal = showAskModal;
  Shell.registerScreen("guardian:help", renderHelp);
  Shell.registerScreen("educator:help", renderHelp);
  Shell.registerScreen("centre_director:help", renderHelp);
  Shell.registerScreen("agency_admin:help", renderHelp);
})(window);
