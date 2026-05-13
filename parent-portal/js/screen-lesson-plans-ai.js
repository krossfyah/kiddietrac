/* ============================================================
   KIDDIETRAC v21 - AI Lesson Plan Generator (director)
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

  function foundationColor(f) {
    const map = {
      belonging:  '#FEF3C7',
      wellbeing:  '#DCFCE7',
      engagement: '#DBEAFE',
      expression: '#EDE9FE',
    };
    return map[f] || '#F3F4F6';
  }

  function nextMonday() {
    const d = new Date();
    const day = d.getDay();
    const diff = day === 1 ? 7 : (8 - day) % 7 || 7;
    d.setDate(d.getDate() + diff);
    return d.toISOString().split('T')[0];
  }

  /* ============================================================
     LESSON PLANS LIST
     ============================================================ */
  async function renderLessonPlansAi(main, ctx) {
    Dom.clear(main);
    const wrap = document.createElement('div');
    main.appendChild(wrap);
    wrap.insertAdjacentHTML('beforeend',
      '<div class="page-header-v17">' +
        '<div>' +
          '<div class="crumbs"><span>Home</span><span class="sep">&gt;</span><span style="color:var(--kt-text-muted);">AI Lesson Plans</span></div>' +
          '<h1>AI-generated Lesson Plans</h1>' +
          '<div class="sub">HDLH-aware weekly plans, drafted in seconds.</div>' +
        '</div>' +
        '<div class="actions">' +
          '<button class="btn btn-primary" id="kt-new-plan">+ Generate plan</button>' +
        '</div>' +
      '</div>' +
      '<div id="kt-list"><div class="loading-state"><div class="spinner"></div></div></div>'
    );

    wrap.querySelector('#kt-new-plan').addEventListener('click', function () {
      window.location.hash = '#lesson-plan-new';
    });

    let data;
    try { data = await Api.get('/director/lesson-plans-ai'); }
    catch (e) {
      const el = wrap.querySelector('#kt-list');
      Dom.clear(el);
      el.appendChild(emptyState('!', 'Could not load', (e && e.message) || 'Server error'));
      return;
    }

    const rows = (data && data.plans) || [];
    const listEl = wrap.querySelector('#kt-list');
    Dom.clear(listEl);

    if (rows.length === 0) {
      listEl.appendChild(emptyState('-', 'No plans yet', 'Click + Generate plan to create your first AI-drafted week.'));
      return;
    }

    rows.forEach(function (p) {
      const card = document.createElement('div');
      card.style.cssText = 'background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:18px; margin-bottom:12px;';
      const days = (p.plan_body && p.plan_body.days) || [];
      card.innerHTML =
        '<div style="display:flex; justify-content:space-between; align-items:flex-start;">' +
          '<div>' +
            '<strong style="font-size:16px;">' + esc(p.theme) + '</strong>' +
            '<div style="font-size:13px; color:var(--kt-text-muted); margin-top:4px;">' +
              'Week of ' + esc(p.week_starting) + ' &middot; ' + esc(p.age_group) + ' &middot; ' + days.length + ' days planned' +
            '</div>' +
          '</div>' +
          '<div>' +
            (p.published ? '<span class="tag tag-success">PUBLISHED</span>' : '<span class="tag tag-info">DRAFT</span>') +
          '</div>' +
        '</div>' +
        (days.length > 0 ?
          '<div style="margin-top:14px; display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:8px;">' +
            days.map(function (d) {
              return '<div style="background:var(--kt-bg); padding:10px; border-radius:8px;">' +
                '<div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--kt-text-faint);">' + esc(d.day) + '</div>' +
                '<div style="font-size:13px; margin-top:4px;">' + esc(d.headline || '') + '</div>' +
              '</div>';
            }).join('') +
          '</div>' : '');
      listEl.appendChild(card);
    });
  }

  /* ============================================================
     NEW PLAN
     ============================================================ */
  async function renderLessonPlanNew(main, ctx) {
    Dom.clear(main);

    // Load centres for current user
    let centres = [];
    try {
      const user = (window.KT.Auth && window.KT.Auth.user()) || {};
      const role = (Shell.Roles && Shell.Roles.primaryRoleOf(user)) || '';
      const url = role === 'agency_admin' ? '/admin/centres' : '/director/centres';
      const r = await Api.get(url);
      centres = r.centres || [];
    } catch (e) {}

    const wrap = document.createElement('div');
    main.appendChild(wrap);
    wrap.insertAdjacentHTML('beforeend',
      '<div class="page-header-v17">' +
        '<div>' +
          '<div class="crumbs">' +
            '<a href="#lesson-plans-ai" style="color:var(--kt-text-muted);">AI Lesson Plans</a>' +
            '<span class="sep">&gt;</span>' +
            '<span style="color:var(--kt-text-muted);">Generate</span>' +
          '</div>' +
          '<h1>Generate a weekly plan</h1>' +
          '<div class="sub">Pick parameters. AI drafts 5 days of HDLH-aligned activities in seconds.</div>' +
        '</div>' +
      '</div>' +

      '<div id="kt-step-1" style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:24px; max-width:680px;">' +
        '<div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">' +
          '<div class="form-row">' +
            '<label>Centre *</label>' +
            '<select id="kt-centre" required style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
              '<option value="">- select -</option>' +
              centres.map(function (c) {
                return '<option value="' + c.id + '">' + esc(c.name) + '</option>';
              }).join('') +
            '</select>' +
          '</div>' +
          '<div class="form-row">' +
            '<label>Age group *</label>' +
            '<select id="kt-age" required style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
              '<option value="infant">Infant (0-18m)</option>' +
              '<option value="toddler" selected>Toddler (18m-3y)</option>' +
              '<option value="preschool">Preschool (3-5y)</option>' +
              '<option value="kindergarten">Kindergarten (4-6y)</option>' +
            '</select>' +
          '</div>' +
          '<div class="form-row" style="grid-column:1/-1;">' +
            '<label>Theme *</label>' +
            '<input type="text" id="kt-theme" required placeholder="e.g. Spring growth, Community helpers, Light and shadow" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
          '</div>' +
          '<div class="form-row">' +
            '<label>Week starting *</label>' +
            '<input type="date" id="kt-week" required value="' + nextMonday() + '" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
          '</div>' +
          '<div class="form-row">' +
            '<label>Room name (optional)</label>' +
            '<input type="text" id="kt-room" placeholder="e.g. Acorn Room" style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; width:100%;">' +
          '</div>' +
          '<div class="form-row" style="grid-column:1/-1;">' +
            '<label>Notes for AI (optional)</label>' +
            '<textarea id="kt-notes" rows="2" placeholder="e.g. Several children are new to the centre; emphasize belonging activities." style="padding:10px; border:1.5px solid var(--kt-border); border-radius:8px; font-family:inherit; width:100%;"></textarea>' +
          '</div>' +
        '</div>' +
        '<div style="margin-top:18px; display:flex; gap:10px;">' +
          '<button class="btn btn-primary" id="kt-generate">Generate with AI</button>' +
          '<button class="btn btn-ghost" id="kt-cancel">Cancel</button>' +
        '</div>' +
        '<div id="kt-status" style="margin-top:14px; font-size:13px;"></div>' +
      '</div>' +

      '<div id="kt-step-2" style="display:none; margin-top:14px;"></div>'
    );

    wrap.querySelector('#kt-cancel').addEventListener('click', function () {
      window.location.hash = '#lesson-plans-ai';
    });

    wrap.querySelector('#kt-generate').addEventListener('click', async function () {
      const centreId = wrap.querySelector('#kt-centre').value;
      const age      = wrap.querySelector('#kt-age').value;
      const theme    = wrap.querySelector('#kt-theme').value.trim();
      const week     = wrap.querySelector('#kt-week').value;
      const room     = wrap.querySelector('#kt-room').value.trim();
      const notes    = wrap.querySelector('#kt-notes').value.trim();
      const status   = wrap.querySelector('#kt-status');
      const btn      = wrap.querySelector('#kt-generate');

      if (!centreId) { status.innerHTML = '<span style="color:#DC2626;">Pick a centre.</span>'; return; }
      if (theme.length < 3) { status.innerHTML = '<span style="color:#DC2626;">Theme needed.</span>'; return; }
      if (!week) { status.innerHTML = '<span style="color:#DC2626;">Pick a week.</span>'; return; }

      btn.disabled = true; btn.textContent = 'Generating...';
      status.innerHTML = '<span style="color:var(--kt-text-muted);">Claude is drafting 5 days... usually 10-20 seconds.</span>';

      try {
        const res = await Api.post('/director/lesson-plans-ai/generate', {
          centre_id: parseInt(centreId, 10),
          age_group: age,
          theme: theme,
          week_starting: week,
          room_name: room || null,
          starter_notes: notes || null,
        });
        renderStep2(wrap, res, { centreId: parseInt(centreId, 10), age, theme, week, room, notes });
      } catch (e) {
        let detail = (e && e.message) || 'Server error';
        if (e && e.body) {
          try {
            const parsed = typeof e.body === 'string' ? JSON.parse(e.body) : e.body;
            detail = parsed.detail || parsed.error || detail;
          } catch (_) {}
        }
        status.innerHTML = '<span style="color:#DC2626;">AI failed: ' + esc(detail) + '</span>';
        btn.disabled = false; btn.textContent = 'Generate with AI';
      }
    });
  }

  function renderStep2(wrap, res, params) {
    const step1 = wrap.querySelector('#kt-step-1');
    const step2 = wrap.querySelector('#kt-step-2');
    step1.style.opacity = '0.6';
    step1.style.pointerEvents = 'none';

    const plan = (res && res.plan) || {};
    const days = (plan.days) || [];
    const meta = (res && res.meta) || {};

    step2.style.display = 'block';
    step2.innerHTML =
      '<div style="background:var(--kt-surface); border:1px solid var(--kt-border); border-radius:14px; padding:24px;">' +
        '<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">' +
          '<div>' +
            '<h2 style="font-family:var(--kt-font-display); font-size:20px; margin:0;">' + esc(plan.theme || params.theme) + '</h2>' +
            '<div style="font-size:13px; color:var(--kt-text-muted); margin-top:4px;">' +
              'Week of ' + esc(params.week) + ' &middot; ' + esc(plan.age_group || params.age) +
            '</div>' +
          '</div>' +
          '<div style="font-size:11px; color:var(--kt-text-faint); text-align:right;">' +
            'Powered by ' + esc(meta.model || 'Claude') +
            (meta.tokens_used ? '<br>' + meta.tokens_used + ' tokens' : '') +
          '</div>' +
        '</div>' +

        (plan.family_share ?
          '<div style="background:var(--kt-bg); padding:14px; border-radius:8px; margin-bottom:14px;">' +
            '<div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--kt-text-faint); margin-bottom:4px;">For families</div>' +
            '<div style="font-size:14px; line-height:1.6;">' + esc(plan.family_share) + '</div>' +
          '</div>' : '') +

        '<div style="display:grid; gap:14px;">' +
          days.map(function (d) {
            const activities = Array.isArray(d.activities) ? d.activities : [];
            return '<div style="border:1px solid var(--kt-border); border-radius:10px; padding:14px;">' +
              '<div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--kt-text-faint);">' + esc(d.day) + '</div>' +
              '<div style="font-size:16px; font-weight:600; margin-top:4px;">' + esc(d.headline || '') + '</div>' +
              (activities.length > 0 ?
                '<div style="margin-top:10px; display:grid; gap:8px;">' +
                  activities.map(function (a) {
                    return '<div style="background:' + foundationColor(a.hdlh_foundation) + '; padding:10px; border-radius:8px;">' +
                      '<div style="font-weight:600; font-size:13px;">' + esc(a.title) + (a.duration_min ? ' &middot; ' + a.duration_min + ' min' : '') + '</div>' +
                      '<div style="font-size:13px; line-height:1.5; margin-top:4px;">' + esc(a.description) + '</div>' +
                      ((Array.isArray(a.materials) && a.materials.length > 0) ?
                        '<div style="font-size:11px; color:var(--kt-text-muted); margin-top:4px;">Materials: ' + esc(a.materials.join(', ')) + '</div>' : '') +
                    '</div>';
                  }).join('') +
                '</div>' : '') +
              (d.family_blurb ? '<div style="margin-top:10px; font-size:12px; font-style:italic; color:var(--kt-text-muted);">Family note: ' + esc(d.family_blurb) + '</div>' : '') +
            '</div>';
          }).join('') +
        '</div>' +

        ((Array.isArray(plan.materials_summary) && plan.materials_summary.length > 0) ?
          '<div style="margin-top:14px; padding:12px; background:var(--kt-bg); border-radius:8px;">' +
            '<div style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--kt-text-faint); margin-bottom:6px;">Materials needed this week</div>' +
            '<div style="font-size:13px; line-height:1.6;">' + esc(plan.materials_summary.join(' &middot; ')) + '</div>' +
          '</div>' : '') +

        '<div style="margin-top:18px; display:flex; gap:10px;">' +
          '<button class="btn btn-primary" id="kt-save-draft">Save as draft</button>' +
          '<button class="btn btn-success" id="kt-save-publish">Save & publish</button>' +
          '<button class="btn btn-secondary" id="kt-redo">Generate again</button>' +
        '</div>' +
        '<div id="kt-save-status" style="margin-top:12px; font-size:13px;"></div>' +
      '</div>';

    step2.querySelector('#kt-redo').addEventListener('click', function () {
      step1.style.opacity = '1';
      step1.style.pointerEvents = 'auto';
      step2.style.display = 'none';
      wrap.querySelector('#kt-generate').disabled = false;
      wrap.querySelector('#kt-generate').textContent = 'Generate with AI';
      wrap.querySelector('#kt-status').innerHTML = '';
    });

    function save(published) {
      return Api.post('/director/lesson-plans-ai/save', {
        centre_id: params.centreId,
        age_group: params.age,
        theme: params.theme,
        week_starting: params.week,
        plan_body: plan,
        source_prompt: params.notes || null,
        model_used: meta.model,
        tokens_used: meta.tokens_used,
        published: !!published,
      });
    }

    step2.querySelector('#kt-save-draft').addEventListener('click', async function () {
      const status = step2.querySelector('#kt-save-status');
      try { await save(false); Dom.toast('Saved as draft', 'success'); setTimeout(function () { window.location.hash = '#lesson-plans-ai'; }, 500); }
      catch (e) { status.innerHTML = '<span style="color:#DC2626;">' + esc(e.message || 'Could not save') + '</span>'; }
    });
    step2.querySelector('#kt-save-publish').addEventListener('click', async function () {
      const status = step2.querySelector('#kt-save-status');
      try { await save(true); Dom.toast('Saved and published', 'success'); setTimeout(function () { window.location.hash = '#lesson-plans-ai'; }, 500); }
      catch (e) { status.innerHTML = '<span style="color:#DC2626;">' + esc(e.message || 'Could not save') + '</span>'; }
    });
  }

  /* ============================================================
     Register
     ============================================================ */
  window.KT = window.KT || {};
  window.KT.renderLessonPlansAi  = renderLessonPlansAi;
  window.KT.renderLessonPlanNew  = renderLessonPlanNew;
  if (Shell && Shell.registerScreen) {
    ['centre_director', 'agency_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':lesson-plans-ai',  renderLessonPlansAi);
      Shell.registerScreen(r + ':lesson-plan-new',  renderLessonPlanNew);
    });
  }
})(window);
