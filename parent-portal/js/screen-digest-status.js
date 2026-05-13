/* ============================================================
   KIDDIETRAC v20 -- Director: AI Digest Status board
   Lets directors see which children got an AI recap today
   and manually regenerate missing ones.
   ============================================================ */
(function (window) {
  'use strict';
  const { Api, Dom, Shell, Fmt } = window.KT;
  const { emptyState } = Shell;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function formatDate(d) {
    if (!d) return '-';
    try { return new Date(d).toLocaleString('en-CA', { hour: 'numeric', minute: 'numeric' }); }
    catch (e) { return d; }
  }

  function statusBadge(status) {
    const map = {
      generated: { cls: 'tag-success', text: 'GENERATED' },
      pending:   { cls: 'tag-warn',    text: 'PENDING'   },
      no_events: { cls: 'tag-info',    text: 'NO EVENTS' },
    };
    const m = map[status] || { cls: 'tag-info', text: status.toUpperCase() };
    return `<span class="tag-v17 ${m.cls.replace('tag-', '')}">${m.text}</span>`;
  }

  async function renderDigestStatus(main, ctx) {
    Dom.clear(main);

    // Skeleton
    const skeleton = document.createElement('div');
    skeleton.innerHTML = `
      <div style="opacity:.5;">
        <div style="height:32px; background:var(--kt-bg); width:280px; border-radius:8px; margin-bottom:24px;"></div>
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:28px;">
          ${'<div style="height:88px; background:var(--kt-bg); border-radius:14px;"></div>'.repeat(4)}
        </div>
        <div style="height:300px; background:var(--kt-bg); border-radius:14px;"></div>
      </div>`;
    main.appendChild(skeleton);

    // Allow ?date=YYYY-MM-DD in hash
    const params = ctx?.params || {};
    const date = params.date || todayISO();

    let data;
    try {
      data = await Api.get(`/director/digest-status?date=${encodeURIComponent(date)}`);
    } catch (e) {
      Dom.clear(main);
      main.appendChild(emptyState('!', 'Could not load digest status', e.message || 'Server error'));
      return;
    }

    Dom.clear(main);
    const wrap = document.createElement('div');
    main.appendChild(wrap);

    // Header
    wrap.insertAdjacentHTML('beforeend', `
      <div class="page-header-v17">
        <div>
          <div class="crumbs"><span>Home</span><span class="sep">&gt;</span><span style="color:var(--kt-text-muted);">AI digest status</span></div>
          <h1>AI Daily Digest</h1>
          <div class="sub">Per-child status for ${esc(date)} - ${data.summary.ai_enabled ? 'AI enabled' : 'AI disabled (templated fallback)'}</div>
        </div>
        <div class="actions">
          <input type="date" id="kt-digest-date" value="${esc(date)}" style="padding:8px 12px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; font-size:14px;">
          <button class="btn btn-secondary" id="kt-digest-refresh" title="Refresh">Refresh</button>
        </div>
      </div>
    `);

    wrap.querySelector('#kt-digest-date')?.addEventListener('change', (e) => {
      window.location.hash = '#digest-status?date=' + encodeURIComponent(e.target.value);
    });
    wrap.querySelector('#kt-digest-refresh')?.addEventListener('click', () => renderDigestStatus(main, ctx));

    // Summary tiles
    const s = data.summary;
    wrap.insertAdjacentHTML('beforeend', `
      <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:24px;">
        <div class="stat-tile-v17 accent-navy">
          <div class="label">Children</div>
          <div class="value">${s.total}</div>
          <div class="delta muted">in scope</div>
        </div>
        <div class="stat-tile-v17 accent-success">
          <div class="label">Generated</div>
          <div class="value">${s.generated}</div>
          <div class="delta muted">${s.total > 0 ? Math.round(s.generated/s.total*100) : 0}% coverage</div>
        </div>
        <div class="stat-tile-v17 ${s.pending > 0 ? 'accent-warn' : 'accent-success'}">
          <div class="label">Pending</div>
          <div class="value">${s.pending}</div>
          <div class="delta muted">${s.pending > 0 ? 'have events, awaiting AI' : 'all caught up'}</div>
        </div>
        <div class="stat-tile-v17 accent-teal">
          <div class="label">No events</div>
          <div class="value">${s.no_events}</div>
          <div class="delta muted">nothing to summarize</div>
        </div>
      </div>
    `);

    if (data.children.length === 0) {
      wrap.appendChild(emptyState('-', 'No children in scope', 'Try a different date or check your enrollment.'));
      return;
    }

    // Table
    const tableWrap = document.createElement('div');
    tableWrap.style.cssText = 'background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; overflow:hidden;';
    tableWrap.innerHTML = `
      <table class="data-table" style="margin:0;">
        <thead>
          <tr>
            <th>Child</th>
            <th>Events</th>
            <th>Status</th>
            <th>Generated at</th>
            <th>Preview</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    `;
    const tbody = tableWrap.querySelector('tbody');

    data.children.forEach(c => {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td><strong>${esc(c.child_name)}</strong></td>
        <td>${c.event_count}</td>
        <td>${statusBadge(c.status)}</td>
        <td style="font-size:12px; color:var(--kt-text-muted);">${formatDate(c.generated_at)}</td>
        <td style="font-size:13px; color:var(--kt-text-muted); max-width:340px; overflow:hidden; text-overflow:ellipsis;">${esc(c.body_preview || '-')}</td>
        <td style="text-align:right;">
          <button class="btn btn-sm btn-secondary kt-regen-btn"
                  data-child-id="${c.child_id}"
                  data-child-name="${esc(c.child_name)}"
                  ${c.event_count === 0 ? 'disabled title="No events to summarize"' : ''}>
            ${c.has_digest ? 'Regenerate' : 'Generate'}
          </button>
        </td>
      `;
      tbody.appendChild(row);
    });
    wrap.appendChild(tableWrap);

    // Wire regen buttons
    wrap.querySelectorAll('.kt-regen-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const childId = btn.getAttribute('data-child-id');
        const childName = btn.getAttribute('data-child-name');
        const original = btn.textContent;

        btn.disabled = true;
        btn.textContent = 'Working...';

        try {
          const result = await Api.post('/director/digest-status/regenerate', {
            child_id: parseInt(childId, 10),
            date: date,
          });

          if (result && result.success) {
            // Show generated text in a modal
            Shell.Modal.open({
              title: `Digest for ${childName}`,
              body: `
                <div style="margin-bottom:16px; padding:12px; background:var(--kt-bg); border-radius:8px; font-size:13px; color:var(--kt-text-muted);">
                  Model: ${esc(result.model_used || 'unknown')}
                  ${result.tokens_used ? ` &middot; ${result.tokens_used} tokens` : ''}
                </div>
                <div style="font-size:15px; line-height:1.6; white-space:pre-wrap;">${esc(result.body)}</div>
              `,
              actions: [{ label: 'Close', style: 'btn-primary' }],
              onClose: () => renderDigestStatus(main, ctx),
            });
          } else {
            const msg = result?.detail || result?.error || 'Generation failed';
            Dom.toast(msg, 'error');
            btn.disabled = false;
            btn.textContent = original;
          }
        } catch (e) {
          // Try to parse the error body for a useful detail
          let detail = e.message || 'Network error';
          if (e.body) {
            try {
              const parsed = typeof e.body === 'string' ? JSON.parse(e.body) : e.body;
              detail = parsed.detail || parsed.error || detail;
            } catch (_) {}
          }
          Dom.toast(detail, 'error');
          btn.disabled = false;
          btn.textContent = original;
        }
      });
    });
  }

  function todayISO() {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  }

  // Register
  window.KT = window.KT || {};
  window.KT.renderDigestStatus = renderDigestStatus;
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:digest-status',   renderDigestStatus);
    Shell.registerScreen('centre_director:digest-status', renderDigestStatus);
  }
})(window);
