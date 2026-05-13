/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Parent Dashboard
   Fetches children, timeline, digest, billing summary for the day
   ═══════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';
  const { Auth, Api, Fmt, Dom, bootstrapPage } = window.KT;

  let state = {
    children: [],
    selectedChildId: null,
    date: new Date().toISOString().split('T')[0],
    today: new Date().toISOString().split('T')[0],
  };

  // ─── Event handlers ─────────────────────────────────────────────
  function bindControls() {
    Dom.$('#prevDayBtn')?.addEventListener('click', () => shiftDate(-1));
    Dom.$('#todayBtn')?.addEventListener('click', () => setDate(state.today));
  }

  function shiftDate(days) {
    const d = new Date(state.date);
    d.setDate(d.getDate() + days);
    setDate(d.toISOString().split('T')[0]);
  }

  function setDate(date) {
    state.date = date;
    Dom.$('#todayBtn').disabled = (date === state.today);
    refreshAll();
  }

  // ─── Data loading ──────────────────────────────────────────────
  async function loadChildren() {
    try {
      const data = await Api.get('/parent/children');
      state.children = data.children || [];
      if (!state.selectedChildId && state.children.length) {
        state.selectedChildId = state.children[0].id;
      }
      renderChildTabs();
    } catch (e) {
      Dom.toast('Could not load children: ' + e.message, 'error');
    }
  }

  function renderChildTabs() {
    const wrap = Dom.$('#childTabs');
    Dom.clear(wrap);
    state.children.forEach(child => {
      const tab = Dom.el('button', {
        class: 'child-tab' + (child.id === state.selectedChildId ? ' active' : ''),
        onClick: () => { state.selectedChildId = child.id; renderChildTabs(); refreshAll(); },
      });
      const avatar = Dom.el('div', { class: 'avatar sm' });
      avatar.textContent = Fmt.initials(child.display_name);
      avatar.style.fontSize = '11px';
      avatar.style.width = '24px';
      avatar.style.height = '24px';
      tab.appendChild(avatar);
      tab.appendChild(document.createTextNode(child.display_name));
      if (child.is_at_centre) {
        const dot = Dom.el('span', { class: 'tag tag-green' }, 'AT CENTRE');
        tab.appendChild(dot);
      }
      wrap.appendChild(tab);
    });
  }

  async function refreshAll() {
    if (!state.selectedChildId) return;
    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    updatePageHeader(child);

    // Load in parallel for snappy UX
    await Promise.allSettled([
      loadDigest(child),
      loadTimeline(child),
      loadBilling(child),
      loadPortfolio(child),
      loadMessages(),
    ]);
  }

  function updatePageHeader(child) {
    const isToday = state.date === state.today;
    Dom.$('#pageTitle').textContent = isToday
      ? `Today with ${child.display_name}`
      : `${Fmt.date(state.date)}`;
    Dom.$('#pageSub').textContent = isToday
      ? `${child.display_name} is ${child.age?.human || ''} · ${child.room_name || ''}`
      : `Looking back at ${child.display_name}'s day`;
  }

  // ─── AI Digest ─────────────────────────────────────────────────
  async function loadDigest(child) {
    const body = Dom.$('#digestBody');
    const meta = Dom.$('#digestMeta');
    const title = Dom.$('#digestTitle');

    try {
      const digest = await Api.get(`/parent/children/${child.id}/digest/${state.date}`);
      Dom.clear(body);

      if (!digest || !digest.body) {
        body.textContent = state.date === state.today
          ? `${child.display_name}'s digest will be ready around 6 PM after the day's events are logged.`
          : `No digest was generated for this day.`;
        meta.textContent = '';
        return;
      }

      title.textContent = state.date === state.today
        ? `How ${child.display_name}'s day went`
        : `${child.display_name} on ${Fmt.date(state.date, { month: 'long', day: 'numeric' })}`;
      body.textContent = digest.body;
      meta.innerHTML = `Generated ${Fmt.relative(digest.generated_at)} · `
        + `Based on ${digest.source_event_ids?.length || 0} logged events`;
    } catch (e) {
      body.textContent = 'Digest is not available right now.';
      meta.textContent = '';
    }
  }

  // ─── Timeline ──────────────────────────────────────────────────
  async function loadTimeline(child) {
    const wrap = Dom.$('#timeline');
    const strip = Dom.$('#todayStrip');
    Dom.clear(wrap);
    Dom.clear(strip);

    try {
      const data = await Api.get(`/parent/children/${child.id}/timeline`, { date: state.date });

      // Stats strip
      renderTodayStrip(data, strip);

      // Combined feed: events + check-ins, sorted newest first
      const combined = [
        ...(data.events || []).map(e => ({ ...e, _kind: 'event' })),
        ...(data.checks || []).map(c => ({
          _kind: 'check',
          type: c.type,
          occurred_at: c.occurred_at,
          time_display: c.time_display,
          display: {
            title: c.type === 'check_in' ? 'Checked in' : 'Checked out',
            detail: `By ${c.by || 'guardian'}` + (c.mood ? ` · ${c.mood}` : ''),
            color: c.type === 'check_in' ? '#3DB6A0' : '#5B6B78',
          },
        })),
      ].sort((a, b) => new Date(b.occurred_at) - new Date(a.occurred_at));

      if (combined.length === 0) {
        wrap.appendChild(emptyState('🌱', 'Nothing logged yet',
          `Educators will log ${child.display_name}'s day as it unfolds.`));
        return;
      }

      combined.forEach(item => wrap.appendChild(renderTimelineItem(item)));
    } catch (e) {
      wrap.appendChild(emptyState('⚠️', 'Could not load timeline', e.message));
    }
  }

  function renderTodayStrip(data, strip) {
    const events = data.events || [];
    const meals = events.filter(e => e.type === 'meal' || e.type === 'snack').length;
    const naps  = events.filter(e => e.type === 'nap_end').length;
    const diapers = events.filter(e => e.type === 'diaper' || e.type === 'bathroom').length;
    const activities = events.filter(e => e.type === 'activity').length;

    const stats = [
      { label: 'Meals & snacks', value: meals, detail: meals ? 'Logged today' : 'Not yet' },
      { label: 'Naps', value: naps, detail: naps ? 'Completed' : 'None yet' },
      { label: 'Diaper / bathroom', value: diapers, detail: 'Total today' },
      { label: 'Activities', value: activities, detail: 'Learning moments' },
    ];

    stats.forEach(s => {
      strip.appendChild(Dom.el('div', { class: 'stat-card' },
        Dom.el('div', { class: 'stat-label' }, s.label),
        Dom.el('div', { class: 'stat-value' }, String(s.value)),
        Dom.el('div', { class: 'stat-detail' }, s.detail),
      ));
    });
  }

  function renderTimelineItem(item) {
    const display = item.display || {};
    const iconClass = item.type === 'meal' || item.type === 'snack' ? 'meal'
      : item.type === 'nap_start' || item.type === 'nap_end' ? 'nap'
      : item.type === 'diaper' || item.type === 'bathroom' ? 'diaper'
      : item.type === 'activity' ? 'activity'
      : item.type === 'incident' ? 'incident'
      : item.type === 'mood' ? 'mood' : '';

    const iconChar = item.type === 'meal' ? '🍽️'
      : item.type === 'snack' ? '🍎'
      : item.type === 'nap_start' ? '😴'
      : item.type === 'nap_end' ? '🌅'
      : item.type === 'diaper' || item.type === 'bathroom' ? '💧'
      : item.type === 'activity' ? '✨'
      : item.type === 'incident' ? '⚠️'
      : item.type === 'mood' ? '😊'
      : item.type === 'check_in' ? '🟢'
      : item.type === 'check_out' ? '👋'
      : '•';

    const row = Dom.el('div', { class: 'timeline-item' },
      Dom.el('div', { class: 'timeline-icon ' + iconClass }, iconChar),
      Dom.el('div', { class: 'timeline-body' },
        Dom.el('div', { class: 'timeline-title' }, display.title || item.type),
        Dom.el('div', { class: 'timeline-detail' }, display.detail || item.notes || ''),
      ),
      Dom.el('div', { class: 'timeline-time' }, item.time_display || Fmt.time(item.occurred_at)),
    );

    if (item.photo?.thumb) {
      row.insertBefore(
        Dom.el('img', { class: 'timeline-photo', src: item.photo.thumb, alt: '' }),
        row.querySelector('.timeline-time'),
      );
    }
    return row;
  }

  // ─── Billing widget ────────────────────────────────────────────
  async function loadBilling(child) {
    const wrap = Dom.$('#billingWidget');
    try {
      const data = await Api.get(`/parent/children/${child.id}/invoices`, { limit: 1, status: 'current' });
      const invoice = data.invoices?.[0];

      Dom.clear(wrap);
      if (!invoice) {
        wrap.appendChild(Dom.el('p', { style: 'color: var(--kt-text-muted); font-size: 14px;' },
          'No invoice yet this month.'));
        return;
      }

      wrap.appendChild(Dom.el('div', { class: 'invoice-row' },
        Dom.el('span', { class: 'label' }, 'Tuition'),
        Dom.el('span', { class: 'value strike' }, Fmt.money(invoice.subtotal)),
      ));
      if (invoice.subsidy_amount > 0) {
        wrap.appendChild(Dom.el('div', { class: 'invoice-row' },
          Dom.el('span', { class: 'label' }, 'CWELCC subsidy'),
          Dom.el('span', { class: 'value' }, '– ' + Fmt.money(invoice.subsidy_amount)),
        ));
      }
      wrap.appendChild(Dom.el('div', { class: 'invoice-row' },
        Dom.el('span', { class: 'label' }, 'Your share'),
        Dom.el('span', { class: 'value total' }, Fmt.money(invoice.total)),
      ));

      const status = (invoice.status === 'paid')
        ? { class: 'tag-success', text: 'PAID' }
        : (invoice.status === 'overdue')
          ? { class: 'tag-danger', text: 'OVERDUE' }
          : { class: 'tag-warn', text: 'DUE ' + Fmt.date(invoice.due_at, { month: 'short', day: 'numeric' }).toUpperCase() };

      wrap.appendChild(Dom.el('div', { style: 'margin-top: 12px;' },
        Dom.el('span', { class: 'tag ' + status.class }, status.text),
      ));

      if (invoice.status !== 'paid') {
        wrap.appendChild(Dom.el('a', {
          class: 'btn btn-primary btn-block btn-sm',
          href: `/billing.html?invoice=${invoice.id}`,
          style: 'margin-top: 14px; text-decoration: none;',
        }, 'Pay now'));
      }
    } catch (e) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('p', { style: 'color: var(--kt-text-muted); font-size: 14px;' },
        'Could not load billing.'));
    }
  }

  // ─── Portfolio widget ──────────────────────────────────────────
  async function loadPortfolio(child) {
    const wrap = Dom.$('#portfolioWidget');
    try {
      const data = await Api.get(`/parent/children/${child.id}/portfolio`, { limit: 3 });
      const observations = data.observations || [];

      Dom.clear(wrap);
      if (observations.length === 0) {
        wrap.appendChild(Dom.el('p', { style: 'color: var(--kt-text-muted); font-size: 14px;' },
          'Observations from your educator will show up here.'));
        return;
      }

      observations.forEach(o => {
        wrap.appendChild(Dom.el('div', { style: 'padding: 10px 0; border-bottom: 1px solid var(--kt-border);' },
          Dom.el('div', { style: 'font-size: 11px; font-weight: 700; color: var(--kt-blue); letter-spacing: 1px; margin-bottom: 4px;' },
            (o.domain || '').toUpperCase()),
          Dom.el('div', { style: 'font-size: 13px; line-height: 1.5; color: var(--kt-text);' },
            o.body.length > 140 ? o.body.substring(0, 140) + '…' : o.body),
          Dom.el('div', { style: 'font-size: 11px; color: var(--kt-text-faint); margin-top: 4px;' },
            Fmt.relative(o.observed_at)),
        ));
      });
    } catch (e) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('p', { style: 'color: var(--kt-text-muted); font-size: 14px;' },
        'Could not load observations.'));
    }
  }

  // ─── Messages widget ───────────────────────────────────────────
  async function loadMessages() {
    const wrap = Dom.$('#messagesWidget');
    try {
      const data = await Api.get('/parent/conversations', { limit: 3 });
      const convs = data.conversations || [];

      Dom.clear(wrap);
      if (convs.length === 0) {
        wrap.appendChild(Dom.el('p', { style: 'color: var(--kt-text-muted); font-size: 14px;' },
          'No messages yet. Educators will reach out as needed.'));
        return;
      }

      convs.forEach(c => {
        wrap.appendChild(Dom.el('div', { style: 'padding: 10px 0; border-bottom: 1px solid var(--kt-border);' },
          Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: baseline;' },
            Dom.el('strong', { style: 'font-size: 13px;' }, c.last_sender_name || 'Centre'),
            Dom.el('span', { style: 'font-size: 11px; color: var(--kt-text-faint);' }, Fmt.relative(c.last_message_at)),
          ),
          Dom.el('div', { style: 'font-size: 13px; color: var(--kt-text-muted); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' },
            c.last_message || ''),
        ));
      });
    } catch (e) { /* silent for sidebar widgets */ }
  }

  // ─── Helpers ───────────────────────────────────────────────────
  function emptyState(emoji, title, sub) {
    return Dom.el('div', { class: 'empty-state' },
      Dom.el('div', { class: 'empty-emoji' }, emoji),
      Dom.el('h3', {}, title),
      Dom.el('p', {}, sub),
    );
  }

  // ─── Init ──────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', async () => {
    await bootstrapPage();
    bindControls();
    await loadChildren();
    await refreshAll();
  });

})();
