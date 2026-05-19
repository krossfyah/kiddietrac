/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v4 — Parent Screen Module
   Real photos gallery · Messages thread · Billing widget · AI digest
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const { Api, Fmt, Dom, Shell } = window.KT;
  const { emptyState } = Shell;

  let state = {
    children: [],
    selectedChildId: null,
    date: new Date().toISOString().split('T')[0],
  };

  async function renderParent(main, ctx) {
    Dom.clear(main);

    const wrap = Dom.el('div', { style: 'max-width: 1800px; margin: 0 auto; padding: 24px;' });
    main.appendChild(wrap);

    if (state.children.length === 0) {
      try {
        const data = await Api.get('/parent/children');
        state.children = data.children || [];
        if (state.children.length > 0) {
          state.selectedChildId = state.children[0].id;
        }
      } catch (e) {
        wrap.appendChild(emptyState('⚠️', 'Could not load', e.message));
        return;
      }
    }

    if (state.children.length === 0) {
      wrap.appendChild(emptyState(
        '👋', 'Welcome to Kiddietrac',
        'Your childcare centre will link your account to your child once enrolled. Check back soon.'
      ));
      return;
    }

    wrap.appendChild(buildChildTabs());

    const hash = (window.location.hash || '#today').replace('#', '').split('?')[0];

    if (hash === 'photos') {
      await renderPhotosTab(wrap);
    } else if (hash === 'messages') {
      await renderMessagesTab(wrap);
    } else if (hash === 'billing') {
      await renderBillingTab(wrap);
    } else {
      await renderTodayTab(wrap);
    }
  }

  function buildChildTabs() {
    if (state.children.length === 1) return Dom.el('div');

    const tabs = Dom.el('div', { style: 'display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid var(--ink-200);' });

    state.children.forEach(c => {
      const active = c.id === state.selectedChildId;
      const tab = Dom.el('button', {
        class: 'child-tab' + (active ? ' active' : ''),
        style: `padding: 12px 20px; background: none; border: none; border-bottom: 3px solid ${active ? 'var(--brand-green)' : 'transparent'}; font-weight: ${active ? '700' : '500'}; color: ${active ? 'var(--brand-blue)' : 'var(--ink-600)'}; cursor: pointer; font-size: 15px;`,
      }, [
        Dom.el('span', { class: 'child-avatar', style: 'background: var(--brand-green); color: white; width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; margin-right: 8px;' }, c.display_name?.[0]?.toUpperCase() || 'C'),
        c.display_name,
      ]);
      tab.addEventListener('click', () => {
        state.selectedChildId = c.id;
        Shell.rerender();
      });
      tabs.appendChild(tab);
    });

    return tabs;
  }

  function buildSubNav(active) {
    const nav = Dom.el('div', { style: 'display: flex; gap: 4px; margin-bottom: 24px; background: var(--ink-50); padding: 4px; border-radius: 12px; max-width: 480px;' });
    const tabs = [
      { id: 'today',    label: 'Today' },
      { id: 'photos',   label: 'Photos' },
      { id: 'messages', label: 'Messages' },
      { id: 'billing',  label: 'Billing' },
    ];
    tabs.forEach(t => {
      const isActive = t.id === active;
      const btn = Dom.el('a', {
        href: '#' + t.id,
        style: `flex: 1; text-align: center; padding: 8px 12px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 600; ${isActive ? 'background: white; color: var(--brand-blue); box-shadow: 0 1px 3px rgba(0,0,0,0.08);' : 'color: var(--ink-600);'}`,
      }, t.label);
      nav.appendChild(btn);
    });
    return nav;
  }

  // ─── TODAY TAB ─────────────────────────────────────────────────

  async function renderTodayTab(wrap) {
    wrap.appendChild(buildSubNav('today'));

    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    // Header
    wrap.appendChild(Dom.el('div', { style: 'margin-bottom: 24px;' }, [
      Dom.el('div', { style: 'display: flex; align-items: center; gap: 12px; margin-bottom: 8px;' }, [
        Dom.el('h1', { style: 'margin: 0; font-size: 32px;' }, `Today with ${child.display_name}`),
        child.is_at_centre
          ? Dom.el('span', { style: 'background: var(--brand-green); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: 0.5px;' }, 'AT CENTRE')
          : Dom.el('span', { style: 'background: var(--ink-200); color: var(--ink-700); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; letter-spacing: 0.5px;' }, 'AT HOME'),
      ]),
      Dom.el('p', { style: 'color: var(--ink-600); margin: 0;' },
        `${child.display_name} · ${child.age?.human || '—'} · ${child.room_name || 'No room assigned'}`
      ),
    ]));

    const grid = Dom.el('div', { style: 'display: grid; grid-template-columns: 2fr 1fr; gap: 24px;' });
    wrap.appendChild(grid);
    const main = Dom.el('div'); grid.appendChild(main);
    const aside = Dom.el('div', { style: 'display: flex; flex-direction: column; gap: 16px;' }); grid.appendChild(aside);

    // AI digest card (top of main column)
    const digestCard = Dom.el('div', {
      style: 'background: linear-gradient(135deg, #1F6080 0%, #2c7894 100%); color: white; padding: 24px; border-radius: 16px; margin-bottom: 24px;',
    });
    main.appendChild(digestCard);
    digestCard.appendChild(Dom.el('div', { style: 'font-size: 12px; font-weight: 700; letter-spacing: 1px; color: #8EC73C; margin-bottom: 8px;' }, '✨ YOUR DAILY DIGEST'));
    const digestBody = Dom.el('div', { style: 'font-size: 15px; line-height: 1.6;' }, 'Loading...');
    digestCard.appendChild(digestBody);

    // Stats strip + timeline (load in parallel)
    const statsRow = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px;' });
    main.appendChild(statsRow);

    const timelineCard = Dom.el('div', { class: 'card' });
    main.appendChild(timelineCard);
    timelineCard.appendChild(Dom.el('h3', { style: 'margin: 0 0 4px 0;' }, 'Timeline'));
    timelineCard.appendChild(Dom.el('p', { style: 'color: var(--ink-600); margin: 0 0 16px 0; font-size: 13px;' }, 'Every moment logged by the educators'));
    const timelineBody = Dom.el('div'); timelineCard.appendChild(timelineBody);

    // Aside: this month's billing + recent observations
    const billingCard = Dom.el('div', { class: 'card' });
    aside.appendChild(billingCard);
    billingCard.appendChild(Dom.el('h3', { style: 'margin: 0 0 12px 0;' }, "This month's billing"));
    const billingBody = Dom.el('div'); billingCard.appendChild(billingBody);
    billingBody.appendChild(Dom.el('p', { style: 'color: var(--ink-600);' }, 'Loading...'));

    const obsCard = Dom.el('div', { class: 'card' });
    aside.appendChild(obsCard);
    obsCard.appendChild(Dom.el('h3', { style: 'margin: 0 0 12px 0;' }, 'Recent observations'));
    const obsBody = Dom.el('div'); obsCard.appendChild(obsBody);
    obsBody.appendChild(Dom.el('p', { style: 'color: var(--ink-600);' }, 'Loading...'));

    // Fire off all the parallel fetches
    Promise.all([
      Api.get(`/parent/children/${child.id}/timeline?date=${state.date}`),
      Api.get(`/parent/children/${child.id}/digest/${state.date}`),
      Api.get(`/parent/children/${child.id}/invoices?status=current`),
      Api.get(`/parent/children/${child.id}/observations`),
    ]).then(([timeline, digest, invoices, observations]) => {
      // Stats
      const meals = (timeline.events || []).filter(e => e.type === 'meal' || e.type === 'snack').length;
      const naps = (timeline.events || []).filter(e => e.type === 'nap_end').length;
      const diapers = (timeline.events || []).filter(e => e.type === 'diaper' || e.type === 'bathroom').length;
      const activities = (timeline.events || []).filter(e => e.type === 'activity').length;
      [['MEALS & SNACKS', meals, 'Logged today'], ['NAPS', naps, 'Completed'], ['DIAPER / BATHROOM', diapers, 'Total today'], ['ACTIVITIES', activities, 'Learning moments']].forEach(([label, n, sub]) => {
        statsRow.appendChild(Dom.el('div', { class: 'card stat-tile', style: 'padding: 16px;' }, [
          Dom.el('div', { style: 'font-size: 11px; font-weight: 700; color: var(--ink-500); letter-spacing: 1px; margin-bottom: 8px;' }, label),
          Dom.el('div', { style: 'font-size: 32px; font-weight: 800; color: var(--brand-blue); line-height: 1;' }, String(n)),
          Dom.el('div', { style: 'font-size: 12px; color: var(--ink-500); margin-top: 4px;' }, sub),
        ]));
      });

      // Timeline
      Dom.clear(timelineBody);
      if (!timeline.events || timeline.events.length === 0) {
        timelineBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); padding: 24px 0;' }, 'No events logged yet today. Check back soon!'));
      } else {
        timeline.events.forEach(ev => {
          const row = Dom.el('div', { style: 'display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--ink-100);' });
          row.appendChild(Dom.el('div', { style: 'background: var(--ink-50); border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;' }, eventIcon(ev.type)));
          const text = Dom.el('div', { style: 'flex: 1;' });
          text.appendChild(Dom.el('div', { style: 'font-weight: 600;' }, ev.display?.title || ev.type));
          if (ev.display?.detail) text.appendChild(Dom.el('div', { style: 'color: var(--ink-600); font-size: 13px;' }, ev.display.detail));
          row.appendChild(text);
          row.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 13px; flex-shrink: 0;' }, ev.time_display));
          timelineBody.appendChild(row);
        });
      }

      // Digest
      Dom.clear(digestBody);
      if (digest.body) {
        digestBody.appendChild(Dom.el('div', { style: 'white-space: pre-wrap;' }, digest.body));
        if (digest.fallback) {
          digestBody.appendChild(Dom.el('div', { style: 'font-size: 11px; opacity: 0.7; margin-top: 12px; font-style: italic;' }, '(Generated from logged events — AI summary unavailable)'));
        }
      } else {
        digestBody.appendChild(Dom.el('div', { style: 'opacity: 0.9;' }, digest.message || `${child.display_name}'s digest will be ready after 4 PM today.`));
      }

      // Billing
      Dom.clear(billingBody);
      const inv = (invoices.invoices || [])[0];
      if (inv) {
        billingBody.appendChild(Dom.el('div', { style: 'font-size: 24px; font-weight: 800; color: var(--brand-blue);' }, '$' + (inv.balance_due ?? inv.total).toFixed(2)));
        billingBody.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-500); margin-bottom: 8px;' }, inv.is_estimate ? 'estimated for this month' : 'balance due'));
        if (!inv.is_estimate) {
          billingBody.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-600);' }, `Invoice ${inv.invoice_number} · due ${inv.due_date}`));
        }
        billingBody.appendChild(Dom.el('a', { href: '#billing', style: 'display: inline-block; margin-top: 12px; font-size: 13px; color: var(--brand-blue); font-weight: 600; text-decoration: none;' }, 'View billing →'));
      } else {
        billingBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); font-size: 13px;' }, 'No invoices yet.'));
      }

      // Observations
      Dom.clear(obsBody);
      const obs = observations.observations || [];
      if (obs.length === 0) {
        obsBody.appendChild(Dom.el('p', { style: 'color: var(--ink-500); font-size: 13px;' }, 'No observations recorded yet.'));
      } else {
        obs.slice(0, 3).forEach(o => {
          const item = Dom.el('div', { style: 'padding: 10px 0; border-bottom: 1px solid var(--ink-100);' });
          item.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 700; color: var(--brand-green); letter-spacing: 0.5px; margin-bottom: 4px;' }, o.domain_label));
          item.appendChild(Dom.el('div', { style: 'font-weight: 600; font-size: 14px; line-height: 1.3;' }, o.title.replace(/^\[Demo\] /, '')));
          item.appendChild(Dom.el('div', { style: 'color: var(--ink-500); font-size: 12px; margin-top: 4px;' }, o.date_display));
          obsBody.appendChild(item);
        });
      }
    }).catch(e => console.error('Today tab load failed:', e));
  }

  // ─── PHOTOS TAB ─────────────────────────────────────────────────

  async function renderPhotosTab(wrap) {
    wrap.appendChild(buildSubNav('photos'));
    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    wrap.appendChild(Dom.el('h1', { style: 'margin: 0 0 24px;' }, `${child.display_name}'s Photos`));

    const gallery = Dom.el('div', { style: 'display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;' });
    wrap.appendChild(gallery);
    gallery.appendChild(Dom.el('p', { style: 'color: var(--ink-500); grid-column: 1 / -1;' }, 'Loading photos…'));

    try {
      const data = await Api.get(`/parent/children/${child.id}/photos`);
      Dom.clear(gallery);
      if (!data.photos || data.photos.length === 0) {
        gallery.appendChild(emptyState('📷', 'No photos yet', `When educators share photos of ${child.display_name}, they'll appear here.`));
        return;
      }
      data.photos.forEach(p => {
        const card = Dom.el('div', { class: 'card', style: 'padding: 0; overflow: hidden;' });
        const img = Dom.el('img', { src: p.url, alt: p.caption || 'Photo', style: 'width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: var(--ink-100);' });
        card.appendChild(img);
        const body = Dom.el('div', { style: 'padding: 12px;' });
        if (p.caption) body.appendChild(Dom.el('div', { style: 'font-size: 14px; line-height: 1.4;' }, p.caption.replace(/^\[Demo\] /, '')));
        body.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-500); margin-top: 4px;' }, p.date_display));
        card.appendChild(body);
        gallery.appendChild(card);
      });
    } catch (e) {
      Dom.clear(gallery);
      gallery.appendChild(emptyState('⚠️', 'Could not load photos', e.message));
    }
  }

  // ─── MESSAGES TAB ───────────────────────────────────────────────

  async function renderMessagesTab(wrap) {
    wrap.appendChild(buildSubNav('messages'));
    wrap.appendChild(Dom.el('h1', { style: 'margin: 0 0 24px;' }, 'Messages'));

    const container = Dom.el('div', { style: 'display: grid; grid-template-columns: 320px 1fr; gap: 24px; min-height: 60vh;' });
    wrap.appendChild(container);

    const list = Dom.el('div', { class: 'card', style: 'padding: 8px; max-height: 70vh; overflow-y: auto;' });
    container.appendChild(list);
    list.appendChild(Dom.el('p', { style: 'padding: 12px; color: var(--ink-500);' }, 'Loading conversations…'));

    const thread = Dom.el('div', { class: 'card', style: 'display: flex; flex-direction: column; min-height: 500px;' });
    container.appendChild(thread);

    try {
      const data = await Api.get('/parent/messages');
      Dom.clear(list);

      if (!data.conversations || data.conversations.length === 0) {
        list.appendChild(Dom.el('p', { style: 'padding: 16px; color: var(--ink-500);' }, 'No conversations yet.'));
        thread.appendChild(emptyState('💬', 'No conversations', 'Messages with your educators will appear here.'));
        return;
      }

      let selectedConvoId = data.conversations[0].conversation_id;

      const renderList = () => {
        Dom.clear(list);
        data.conversations.forEach(c => {
          const isActive = c.conversation_id === selectedConvoId;
          const item = Dom.el('div', {
            style: `padding: 12px; border-radius: 8px; cursor: pointer; margin-bottom: 4px; ${isActive ? 'background: var(--ink-100);' : ''}`,
          });
          item.appendChild(Dom.el('div', { style: 'display: flex; align-items: center; gap: 8px; margin-bottom: 4px;' }, [
            Dom.el('div', { style: `width: 10px; height: 10px; border-radius: 50%; background: ${c.room_color || '#1F6080'};` }),
            Dom.el('div', { style: 'font-weight: 600;' }, c.child_name),
            c.unread_count > 0
              ? Dom.el('span', { style: 'background: var(--brand-green); color: white; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; margin-left: auto;' }, String(c.unread_count))
              : '',
          ]));
          item.appendChild(Dom.el('div', { style: 'font-size: 13px; color: var(--ink-600);' }, c.room_name));
          if (c.last_message) {
            const preview = c.last_message.body.length > 60 ? c.last_message.body.slice(0, 60) + '…' : c.last_message.body;
            item.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-500); margin-top: 4px;' }, (c.last_message.from_me ? 'You: ' : '') + preview));
          }
          item.addEventListener('click', () => {
            selectedConvoId = c.conversation_id;
            renderList();
            loadThread(selectedConvoId);
          });
          list.appendChild(item);
        });
      };

      const loadThread = async (convoId) => {
        Dom.clear(thread);
        thread.appendChild(Dom.el('p', { style: 'padding: 16px; color: var(--ink-500);' }, 'Loading messages…'));
        try {
          const msgData = await Api.get('/parent/messages/' + convoId);
          Dom.clear(thread);

          const msgList = Dom.el('div', { style: 'flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px;' });
          thread.appendChild(msgList);

          (msgData.messages || []).forEach(m => {
            const bubble = Dom.el('div', {
              style: `max-width: 70%; padding: 10px 14px; border-radius: 16px; ${m.from_me ? 'align-self: flex-end; background: var(--brand-blue); color: white; border-bottom-right-radius: 4px;' : 'align-self: flex-start; background: var(--ink-100); color: var(--ink-900); border-bottom-left-radius: 4px;'}`,
            });
            if (!m.from_me) {
              bubble.appendChild(Dom.el('div', { style: 'font-size: 11px; font-weight: 700; opacity: 0.8; margin-bottom: 4px;' }, m.sender_name));
            }
            bubble.appendChild(Dom.el('div', {}, m.body));
            bubble.appendChild(Dom.el('div', { style: 'font-size: 11px; opacity: 0.7; margin-top: 4px;' }, m.time_display));
            msgList.appendChild(bubble);
          });
          msgList.scrollTop = msgList.scrollHeight;

          // Compose box
          const compose = Dom.el('form', { style: 'border-top: 1px solid var(--ink-200); padding: 12px; display: flex; gap: 8px;' });
          const input = Dom.el('input', { type: 'text', placeholder: 'Type a message…', style: 'flex: 1; padding: 10px 14px; border: 1px solid var(--ink-300); border-radius: 24px; font-size: 14px;' });
          const sendBtn = Dom.el('button', { type: 'submit', style: 'background: var(--brand-blue); color: white; border: none; padding: 0 20px; border-radius: 24px; font-weight: 600; cursor: pointer;' }, 'Send');
          compose.appendChild(input); compose.appendChild(sendBtn);
          compose.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            if (!input.value.trim()) return;
            sendBtn.disabled = true;
            try {
              await Api.post('/parent/messages', { conversation_id: convoId, body: input.value.trim() });
              input.value = '';
              loadThread(convoId);
            } catch (e) {
              alert('Could not send: ' + e.message);
            } finally {
              sendBtn.disabled = false;
            }
          });
          thread.appendChild(compose);
        } catch (e) {
          Dom.clear(thread);
          thread.appendChild(emptyState('⚠️', 'Could not load', e.message));
        }
      };

      renderList();
      loadThread(selectedConvoId);
    } catch (e) {
      Dom.clear(list);
      list.appendChild(emptyState('⚠️', 'Could not load', e.message));
    }
  }

  // ─── BILLING TAB ────────────────────────────────────────────────

  async function renderBillingTab(wrap) {
    wrap.appendChild(buildSubNav('billing'));
    const child = state.children.find(c => c.id === state.selectedChildId);
    if (!child) return;

    wrap.appendChild(Dom.el('h1', { style: 'margin: 0 0 24px;' }, 'Billing'));

    const container = Dom.el('div');
    wrap.appendChild(container);
    container.appendChild(Dom.el('p', { style: 'color: var(--ink-500);' }, 'Loading invoices…'));

    try {
      const data = await Api.get(`/parent/children/${child.id}/invoices`);
      Dom.clear(container);

      if (!data.invoices || data.invoices.length === 0) {
        container.appendChild(emptyState('📄', 'No invoices yet', 'When your monthly invoice is generated, it will appear here.'));
        return;
      }

      data.invoices.forEach(inv => {
        const card = Dom.el('div', { class: 'card', style: 'margin-bottom: 16px;' });
        const head = Dom.el('div', { style: 'display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;' });
        head.appendChild(Dom.el('div', {}, [
          Dom.el('div', { style: 'font-weight: 700; font-size: 18px;' }, inv.invoice_number),
          Dom.el('div', { style: 'color: var(--ink-600); font-size: 13px;' }, `Issued ${inv.issue_date} · Due ${inv.due_date}`),
        ]));
        const statusColor = inv.status === 'paid' ? 'var(--brand-green)' : (inv.status === 'overdue' ? '#c0392b' : 'var(--ink-600)');
        head.appendChild(Dom.el('span', { style: `background: ${statusColor}; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 700; text-transform: uppercase;` }, inv.status_label || inv.status));
        card.appendChild(head);

        const breakdown = Dom.el('div', { style: 'background: var(--ink-50); padding: 16px; border-radius: 8px; display: grid; grid-template-columns: 1fr auto; gap: 8px; font-size: 14px;' });
        breakdown.appendChild(Dom.el('div', { style: 'color: var(--ink-600);' }, 'Subtotal'));
        breakdown.appendChild(Dom.el('div', { style: 'text-align: right;' }, '$' + inv.subtotal.toFixed(2)));
        if (inv.subsidy_amount > 0) {
          breakdown.appendChild(Dom.el('div', { style: 'color: var(--ink-600);' }, 'CWELCC subsidy'));
          breakdown.appendChild(Dom.el('div', { style: 'text-align: right; color: var(--brand-green);' }, '−$' + inv.subsidy_amount.toFixed(2)));
        }
        breakdown.appendChild(Dom.el('div', { style: 'font-weight: 700; font-size: 16px; border-top: 1px solid var(--ink-200); padding-top: 8px;' }, 'Your portion'));
        breakdown.appendChild(Dom.el('div', { style: 'text-align: right; font-weight: 700; font-size: 16px; border-top: 1px solid var(--ink-200); padding-top: 8px; color: var(--brand-blue);' }, '$' + inv.total.toFixed(2)));
        card.appendChild(breakdown);

        if (inv.balance_due > 0) {
          card.appendChild(Dom.el('div', { style: 'background: #FEF3C7; border-left: 3px solid #F59E0B; padding: 12px; margin-top: 12px; font-size: 13px; border-radius: 4px;' },
            `Balance due: $${inv.balance_due.toFixed(2)} · Please pay by ${inv.due_date}. Contact your centre for payment instructions.`
          ));
        }
        container.appendChild(card);
      });
    } catch (e) {
      Dom.clear(container);
      container.appendChild(emptyState('⚠️', 'Could not load billing', e.message));
    }
  }

  function eventIcon(type) {
    return ({
      'meal': '🍽️', 'snack': '🍎', 'nap_start': '😴', 'nap_end': '🌅',
      'diaper': '👶', 'bathroom': '🚽', 'activity': '✨', 'mood': '😊',
      'note': '📝', 'milestone': '🌟', 'bottle': '🍼',
    })[type] || '•';
  }

  window.KT = window.KT || {};
  window.KT.renderParent = renderParent;
  Shell.registerScreen("guardian:dashboard", renderParent);
  Shell.registerScreen("guardian:today", renderParent);
  Shell.registerScreen("guardian:photos", renderParent);
  Shell.registerScreen("guardian:messages", renderParent);
  Shell.registerScreen("guardian:billing", renderParent);
})(window);
