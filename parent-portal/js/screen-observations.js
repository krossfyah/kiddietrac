/* ============================================================
   KIDDIETRAC v21 - AI Observation Notes (educator)
   ============================================================ */
(function (window) {
  'use strict';
  const { Api, Dom, Shell } = window.KT;
  const { emptyState } = Shell;

  function esc(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function fmt(d) {
    if (!d) return '-';
    try {
      return new Date(d).toLocaleString('en-CA', {
        month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
      });
    } catch (e) { return d; }
  }

  function foundationBadge(f) {
    const map = {
      belonging:  { bg: '#FEF3C7', fg: '#92400E', label: 'Belonging' },
      wellbeing:  { bg: '#DCFCE7', fg: '#166534', label: 'Well-being' },
      engagement: { bg: '#DBEAFE', fg: '#1E40AF', label: 'Engagement' },
      expression: { bg: '#EDE9FE', fg: '#5B21B6', label: 'Expression' },
    };
    const m = map[f] || { bg: '#F3F4F6', fg: '#374151', label: f };
    return '<span style="background:' + m.bg + ';color:' + m.fg + ';padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;">' + m.label + '</span>';
  }

  /* ============================================================
     OBSERVATIONS LIST
     ============================================================ */
  async function renderObservations(main, ctx) {
    Dom.clear(main);
    const wrap = document.createElement('div');
    main.appendChild(wrap);

    wrap.insertAdjacentHTML('beforeend',
      '<div class="page-header-v17">' +
        '<div>' +
          '<div class="crumbs"><span>Home</span><span class="sep">&gt;</span><span style="color:var(--kt-text-muted);">Observations</span></div>' +
          '<h1>Learning Observations</h1>' +
          '<div class="sub">Capture and share developmental moments</div>' +
        '</div>' +
        '<div class="actions">' +
          '<button class="btn btn-primary" id="kt-new-obs">+ New observation</button>' +
        '</div>' +
      '</div>' +
      '<div id="kt-obs-list"><div class="loading-state"><div class="spinner"></div></div></div>'
    );

    wrap.querySelector('#kt-new-obs').addEventListener('click', function () {
      window.location.hash = '#observation-new';
    });

    let data;
    try {
      data = await Api.get('/provider/observations?limit=30');
    } catch (e) {
      const el = wrap.querySelector('#kt-obs-list');
      Dom.clear(el);
      el.appendChild(emptyState('!', 'Could not load', (e && e.message) || 'Server error'));
      return;
    }

    const rows = (data && data.observations) || [];
    const listEl = wrap.querySelector('#kt-obs-list');
    listEl.setAttribute('data-kt-list', '1');
    Dom.clear(listEl);

    if (rows.length === 0) {
      listEl.appendChild(emptyState('-', 'No observations yet',
        'Click "+ New observation" to capture your first one with AI structuring.'));
      return;
    }

    rows.forEach(function (o) {
      const card = document.createElement('div');
      card.style.cssText = 'background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:18px; margin-bottom:12px;';
      const milestones = Array.isArray(o.hdlh_milestones) ? o.hdlh_milestones : [];
      const badges = milestones.map(function (m) { return foundationBadge(m.foundation); }).join(' ');
      card.innerHTML =
        '<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">' +
          '<div>' +
            '<strong style="font-size:15px;">' + esc(o.child_name) + '</strong>' +
            ' <span style="font-size:13px; color:var(--kt-text-muted);">' + fmt(o.observed_at) + '</span>' +
          '</div>' +
          '<div>' + badges + (o.ai_generated ? ' <span style="background:#E0F2FE; color:#0369A1; padding:3px 9px; border-radius:10px; font-size:11px; font-weight:700;">AI</span>' : '') + (o.shared_with_family ? ' <span style="background:#DCFCE7; color:#166534; padding:3px 9px; border-radius:10px; font-size:11px; font-weight:700;">SHARED</span>' : '') + '</div>' +
        '</div>' +
        '<p style="margin:0; line-height:1.6;">' + esc(o.family_summary || o.body) + '</p>' +
        (milestones.length > 0 ?
          '<div style="margin-top:12px; padding-top:10px; border-top:1px solid var(--kt-border);">' +
            '<div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--kt-text-faint); margin-bottom:6px;">Milestones</div>' +
            '<ul style="margin:0; padding-left:20px; font-size:13px; line-height:1.6;">' +
              milestones.map(function (m) {
                return '<li>' + esc(m.milestone) + (m.evidence ? ' <em style="color:var(--kt-text-muted);">("' + esc(m.evidence) + '")</em>' : '') + '</li>';
              }).join('') +
            '</ul>' +
          '</div>' : '');
      listEl.appendChild(card);
    });
  }

  /* ============================================================
     NEW OBSERVATION (educator: type -> structure -> edit -> save)
     ============================================================ */
  async function renderObservationNew(main, ctx) {
    Dom.clear(main);

    // Load children roster
    let children = [];
    try {
      const r = await Api.get('/provider/bootstrap');
      if (r && r.rooms) {
        r.rooms.forEach(function (room) {
          if (Array.isArray(room.children)) {
            room.children.forEach(function (c) { children.push(c); });
          }
        });
      }
    } catch (e) {}

    const wrap = document.createElement('div');
    main.appendChild(wrap);
    wrap.insertAdjacentHTML('beforeend',
      '<div class="page-header-v17">' +
        '<div>' +
          '<div class="crumbs">' +
            '<a href="#observations" style="color:var(--kt-text-muted);">Observations</a>' +
            '<span class="sep">&gt;</span>' +
            '<span style="color:var(--kt-text-muted);">New</span>' +
          '</div>' +
          '<h1>New observation</h1>' +
          '<div class="sub">Step 1: type what you observed. Step 2: AI structures it. Step 3: review and save.</div>' +
        '</div>' +
      '</div>' +

      '<div id="kt-step-1" style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:24px; max-width:760px;">' +
        '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin-bottom:14px;">Step 1: Capture the moment</h2>' +

        '<div class="form-row">' +
          '<label>Child *</label>' +
          '<select id="kt-child" required style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
            '<option value="">- select -</option>' +
            children.map(function (c) {
              return '<option value="' + c.id + '">' + esc(((c.first_name||'') + ' ' + (c.last_name||'')).trim()) + '</option>';
            }).join('') +
          '</select>' +
        '</div>' +

        '<div class="form-row" style="margin-top:14px;">' +
          '<label>What did you observe? *</label>' +
          '<textarea id="kt-raw" rows="6" required minlength="10" maxlength="3000" placeholder="Be factual and specific. Example: \'Aria stacked five blocks in a vertical tower, knocked them down, and laughed. She tried again, this time arranging them in a circle and asked Sophia to join her.\'" style="padding:12px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; line-height:1.5; width:100%;"></textarea>' +
          '<div style="font-size:11px; color:var(--kt-text-faint); margin-top:6px;">Tip: write what you saw, not how you felt about it. The AI will surface skills.</div>' +
        '</div>' +

        '<div style="margin-top:18px; display:flex; gap:10px; flex-wrap:wrap;">' +
          '<button class="btn btn-primary" id="kt-structure">Structure with AI</button>' +
          '<button class="btn btn-secondary" id="kt-manual">Write manually (no AI)</button>' +
          '<button class="btn btn-ghost" id="kt-cancel">Cancel</button>' +
        '</div>' +

        '<div id="kt-status" style="margin-top:14px; font-size:13px;"></div>' +
      '</div>' +

      '<div id="kt-step-2" style="display:none; margin-top:14px;"></div>'
    );

    wrap.querySelector('#kt-cancel').addEventListener('click', function () {
      window.location.hash = '#observations';
    });

    /* v22p72: manual fallback — save a moment without the AI structure step
       (works even when Anthropic credits are exhausted) */
    wrap.querySelector('#kt-manual').addEventListener('click', function () {
      const childId = wrap.querySelector('#kt-child').value;
      const rawText = wrap.querySelector('#kt-raw').value.trim();
      const status = wrap.querySelector('#kt-status');
      if (!childId) { status.innerHTML = '<span style="color:#DC2626;">Please select a child.</span>'; return; }
      if (rawText.length < 10) { status.innerHTML = '<span style="color:#DC2626;">Need at least 10 characters.</span>'; return; }
      renderStep2(wrap, {
        structured: { domain: 'cognitive', hdlh_milestones: [], parent_summary: rawText },
        meta: { model: 'manual (no AI)' }
      }, childId, rawText);
    });

    wrap.querySelector('#kt-structure').addEventListener('click', async function () {
      const childId = wrap.querySelector('#kt-child').value;
      const rawText = wrap.querySelector('#kt-raw').value.trim();
      const status = wrap.querySelector('#kt-status');
      const btn = wrap.querySelector('#kt-structure');

      if (!childId) { status.innerHTML = '<span style="color:#DC2626;">Please select a child.</span>'; return; }
      if (rawText.length < 10) { status.innerHTML = '<span style="color:#DC2626;">Need at least 10 characters.</span>'; return; }

      btn.disabled = true; btn.textContent = 'AI is structuring...';
      status.innerHTML = '<span style="color:var(--kt-text-muted);">Calling Claude... usually 5-10 seconds.</span>';

      try {
        const res = await Api.post('/provider/observations/structure', {
          child_id: parseInt(childId, 10),
          raw_text: rawText,
        });
        renderStep2(wrap, res, childId, rawText);
      } catch (e) {
        let detail = (e && e.message) || 'Server error';
        if (e && e.body) {
          try {
            const parsed = typeof e.body === 'string' ? JSON.parse(e.body) : e.body;
            detail = parsed.detail || parsed.error || detail;
          } catch (_) {}
        }
        status.innerHTML = '<span style="color:#DC2626;">AI failed: ' + esc(detail) + '</span>';
        btn.disabled = false; btn.textContent = 'Structure with AI';
      }
    });
  }

  function renderStep2(wrap, res, childId, rawText) {
    const step1 = wrap.querySelector('#kt-step-1');
    const step2 = wrap.querySelector('#kt-step-2');
    step1.style.opacity = '0.6';
    step1.style.pointerEvents = 'none';

    const structured = (res && res.structured) || {};
    const milestones = Array.isArray(structured.hdlh_milestones) ? structured.hdlh_milestones : [];
    const meta = (res && res.meta) || {};

    step2.style.display = 'block';
    step2.innerHTML =
      '<div style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:24px; max-width:760px;">' +
        '<h2 style="font-family:var(--kt-font-display); font-size:18px; margin-bottom:6px;">Step 2: Review and edit</h2>' +
        '<div style="font-size:12px; color:var(--kt-text-faint); margin-bottom:14px;">' +
          'Powered by ' + esc(meta.model || 'Claude') + (meta.tokens_used ? ' &middot; ' + meta.tokens_used + ' tokens' : '') +
        '</div>' +

        '<div class="form-row">' +
          '<label>Domain</label>' +
          '<select id="kt-domain" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
            ['cognitive','social_emotional','physical','language','creative_expression'].map(function (d) {
              return '<option value="' + d + '"' + (d === structured.domain ? ' selected' : '') + '>' + esc(d.replace(/_/g, ' ')) + '</option>';
            }).join('') +
          '</select>' +
        '</div>' +

        '<div class="form-row" style="margin-top:14px;">' +
          '<label>Family summary (parent will see this)</label>' +
          '<textarea id="kt-summary" rows="4" style="padding:12px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; line-height:1.5; width:100%;">' + esc(structured.parent_summary || '') + '</textarea>' +
          '<div style="font-size:11px; color:var(--kt-text-faint); margin-top:6px;">You can edit before publishing.</div>' +
        '</div>' +

        (milestones.length > 0 ?
          '<div style="margin-top:14px;">' +
            '<label style="font-size:13px; font-weight:600;">HDLH Milestones detected</label>' +
            '<div style="margin-top:8px;">' +
              milestones.map(function (m) {
                return '<div style="background:var(--kt-bg); padding:10px; border-radius:8px; margin-bottom:6px;">' +
                  foundationBadge(m.foundation) +
                  ' <strong style="font-size:13px;">' + esc(m.milestone) + '</strong>' +
                  (m.evidence ? '<div style="font-size:12px; color:var(--kt-text-muted); margin-top:4px;"><em>Evidence: ' + esc(m.evidence) + '</em></div>' : '') +
                '</div>';
              }).join('') +
            '</div>' +
          '</div>' : '<div style="margin-top:14px; padding:12px; background:var(--kt-bg); border-radius:8px; font-size:13px; color:var(--kt-text-muted);">No specific HDLH milestones detected. The observation will still save.</div>') +

        '<div style="margin-top:18px; display:flex; gap:10px; align-items:center;">' +
          '<label style="display:flex; align-items:center; gap:6px; cursor:pointer;">' +
            '<input type="checkbox" id="kt-share" checked>' +
            '<span style="font-size:13px;">Share with family right away</span>' +
          '</label>' +
        '</div>' +

        '<div style="margin-top:14px; display:flex; gap:10px;">' +
          '<button class="btn btn-primary" id="kt-save">Save observation</button>' +
          '<button class="btn btn-secondary" id="kt-redo">Restart</button>' +
        '</div>' +

        '<div id="kt-save-status" style="margin-top:12px; font-size:13px;"></div>' +
      '</div>';

    step2.querySelector('#kt-redo').addEventListener('click', function () {
      step1.style.opacity = '1';
      step1.style.pointerEvents = 'auto';
      step2.style.display = 'none';
      wrap.querySelector('#kt-structure').disabled = false;
      wrap.querySelector('#kt-structure').textContent = 'Structure with AI';
      wrap.querySelector('#kt-status').innerHTML = '';
    });

    step2.querySelector('#kt-save').addEventListener('click', async function () {
      const btn = step2.querySelector('#kt-save');
      const status = step2.querySelector('#kt-save-status');
      const domain = step2.querySelector('#kt-domain').value;
      const summary = step2.querySelector('#kt-summary').value.trim();
      const share = step2.querySelector('#kt-share').checked;

      if (!summary) { status.innerHTML = '<span style="color:#DC2626;">Summary cannot be empty.</span>'; return; }
      btn.disabled = true; btn.textContent = 'Saving...';

      try {
        await Api.post('/provider/observations/save', {
          child_id: parseInt(childId, 10),
          raw_text: rawText,
          structured: {
            domain: domain,
            hdlh_milestones: milestones,
            parent_summary: summary,
          },
          shared_with_family: share,
          ai_generated: (meta.model || '').indexOf('manual') === -1,
          ai_model_used: meta.model,
          ai_tokens_used: meta.tokens_used,
        });
        Dom.toast('Observation saved', 'success');
        setTimeout(function () { window.location.hash = '#observations'; }, 500);
      } catch (e) {
        status.innerHTML = '<span style="color:#DC2626;">' + esc((e && e.message) || 'Could not save') + '</span>';
        btn.disabled = false; btn.textContent = 'Save observation';
      }
    });
  }

  /* ============================================================
     Register
     ============================================================ */
  window.KT = window.KT || {};
  window.KT.renderObservations    = renderObservations;
  window.KT.renderObservationNew  = renderObservationNew;
  if (Shell && Shell.registerScreen) {
    ['educator', 'centre_director', 'agency_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':observations',     renderObservations);
      Shell.registerScreen(r + ':observation-new',  renderObservationNew);
    });
  }
})(window);
