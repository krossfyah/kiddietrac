/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p49 — Care logs, milestones, portfolio, time clock,
   tour bookings. One file, multiple hashes.

   Hashes:
     #care-log          quick-tap daily care log entry (educator/director)
     #portfolio?id=N    chronological observation timeline for one child
     #milestones?id=N   developmental milestones checklist for one child
     #time-clock        staff punch in/out + history
     #tours             agency_admin/director view of tour bookings
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function fmt(d) {
    if (!d) return '—';
    try {
      // v22p75.1: MySQL datetimes use a space ("2026-06-15 10:00:00") which
      // Safari/Firefox parse as Invalid Date. Normalise to ISO before parsing.
      var iso = (typeof d === 'string') ? d.replace(' ', 'T') : d;
      var dt = new Date(iso);
      if (isNaN(dt.getTime())) return String(d);
      return dt.toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) { return String(d); }
  }
  function fmtDateOnly(d) {
    if (!d) return '';
    try {
      var iso = (typeof d === 'string') ? d.replace(' ', 'T') : d;
      var dt = new Date(iso);
      if (isNaN(dt.getTime())) return String(d);
      return dt.toLocaleDateString('en-CA', { dateStyle: 'medium' });
    } catch (e) { return String(d); }
  }
  function paramId() {
    var m = (window.location.hash || '').match(/[?&]id=(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
  }

  // ─── Time clock ───────────────────────────────────────────────────
  function renderTimeClock(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:760px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#1F6080 0%,#7C3AED 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">⏱ TIME CLOCK</div><h1>Punch in / punch out</h1><div class="kt-hero-sub">Tap the big button when you start or end a shift. Your manager sees the rolled-up hours in the centre dashboard.</div>';
    wrap.appendChild(hero);

    var card = Dom.el('div', { style: 'background:white;border-radius:14px;padding:32px;margin:18px 0;box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;' });
    var statusEl = Dom.el('div', { style: 'font-size:14px;color:#6B7280;margin-bottom:10px;' }, 'Loading…');
    card.appendChild(statusEl);
    var btn = Dom.el('button', {
      style: 'background:#16A34A;color:white;border:none;padding:18px 40px;border-radius:14px;font-weight:800;cursor:pointer;font-size:18px;box-shadow:0 4px 14px rgba(22,163,74,.28);transition:transform .15s,box-shadow .15s;',
    }, 'Clock in');
    btn.addEventListener('mouseenter', function () { btn.style.transform = 'translateY(-1px)'; });
    btn.addEventListener('mouseleave', function () { btn.style.transform = ''; });
    card.appendChild(btn);
    wrap.appendChild(card);

    var history = Dom.el('div', { style: 'background:white;border-radius:14px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.05);' });
    history.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:13px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, 'Recent punches'));
    var list = Dom.el('div'); history.appendChild(list);
    list.appendChild(Dom.el('div', { style: 'padding:18px;color:#9CA3AF;font-size:13px;text-align:center;' }, 'Loading…'));
    wrap.appendChild(history);

    function refresh() {
      Api.get('/staff/punches/me').then(function (data) {
        var open = (data.punches || []).find(function (p) { return !p.punched_out_at; });
        if (open) {
          var since = new Date(open.punched_in_at);
          var hrs = (Date.now() - since.getTime()) / 3600000;
          statusEl.innerHTML = 'You\'re clocked in since <strong>' + fmt(open.punched_in_at) + '</strong> · ' + hrs.toFixed(1) + ' h elapsed';
          btn.textContent = 'Clock out';
          btn.style.background = '#DC2626';
          btn.style.boxShadow = '0 4px 14px rgba(220,38,38,.28)';
        } else {
          statusEl.textContent = 'You\'re currently clocked out.';
          btn.textContent = 'Clock in';
          btn.style.background = '#16A34A';
          btn.style.boxShadow = '0 4px 14px rgba(22,163,74,.28)';
        }

        Dom.clear(list);
        if (!data.punches || !data.punches.length) {
          list.appendChild(Dom.el('div', { style: 'padding:18px;color:#9CA3AF;font-size:13px;text-align:center;' }, 'No punches yet — tap the button to start your first shift.'));
          return;
        }
        data.punches.forEach(function (p) {
          var row = Dom.el('div', { style: 'display:flex;justify-content:space-between;padding:8px 4px;border-bottom:1px solid #F3F4F6;font-size:13px;' });
          var inO = new Date(p.punched_in_at);
          var outO = p.punched_out_at ? new Date(p.punched_out_at) : null;
          var dur = outO ? ((outO - inO) / 3600000).toFixed(2) + ' h' : '— in progress —';
          row.appendChild(Dom.el('div', { style: 'color:#111827;' }, fmt(p.punched_in_at) + (outO ? ' → ' + outO.toLocaleTimeString() : '')));
          row.appendChild(Dom.el('div', { style: 'color:#1F6080;font-weight:600;' }, dur));
          list.appendChild(row);
        });
      });
    }
    btn.addEventListener('click', function () {
      btn.disabled = true; btn.textContent = '…';
      Api.post('/staff/punch', {}).then(function () { refresh(); }).catch(function (e) {
        alert('Failed: ' + (e.message || 'error'));
        refresh();
      }).finally(function () { btn.disabled = false; });
    });
    refresh();
  }

  // ─── Daily care log entry — quick-tap UI ──────────────────────────
  function renderCareLog(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:780px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#8EC73C 0%,#1F6080 60%,#7C3AED 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📝 DAILY LOG</div><h1>Log a moment</h1><div class="kt-hero-sub">Quick-tap diaper / bathroom / nap / meal / bottle entries. They roll up into the parent\'s Today screen instantly.</div>';
    wrap.appendChild(hero);

    var childSelWrap = Dom.el('div', { style: 'margin:18px 0;background:white;padding:16px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);' });
    childSelWrap.appendChild(Dom.el('label', { style: 'display:block;font-size:12px;font-weight:700;color:#6B7280;letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px;' }, 'Child'));
    var childSel = Dom.el('select', { style: 'width:100%;padding:10px;border:1px solid #D1D5DB;border-radius:10px;font-size:14px;background:white;' });
    childSel.appendChild(Dom.el('option', { value: '' }, 'Loading children…'));
    childSelWrap.appendChild(childSel);
    wrap.appendChild(childSelWrap);

    var grid = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;' });
    var TYPES = [
      { type: 'diaper',    icon: '🧷', label: 'Diaper',    color: '#FF8A65' },
      { type: 'bathroom',  icon: '🚽', label: 'Bathroom',  color: '#1F6080' },
      { type: 'nap',       icon: '😴', label: 'Nap',       color: '#7C3AED' },
      { type: 'meal',      icon: '🍽️', label: 'Meal',      color: '#16A34A' },
      { type: 'snack',     icon: '🍎', label: 'Snack',     color: '#F59E0B' },
      { type: 'bottle',    icon: '🍼', label: 'Bottle',    color: '#8EC73C' },
      { type: 'sunscreen', icon: '☀️', label: 'Sunscreen', color: '#F59E0B' },
      { type: 'mood',      icon: '🙂', label: 'Mood',      color: '#7C3AED' },
    ];
    TYPES.forEach(function (t) {
      var btn = Dom.el('button', {
        type: 'button',
        style: 'background:white;border:2px solid #E5E7EB;border-radius:14px;padding:18px 12px;cursor:pointer;font-size:14px;font-weight:700;color:#0F172A;display:flex;flex-direction:column;align-items:center;gap:6px;transition:transform .15s,border-color .15s,background .15s;',
      });
      btn.innerHTML = '<span style="font-size:30px;">' + t.icon + '</span><span>' + t.label + '</span>';
      btn.addEventListener('mouseenter', function () { btn.style.borderColor = t.color; btn.style.background = '#FAFBFC'; });
      btn.addEventListener('mouseleave', function () { btn.style.borderColor = '#E5E7EB'; btn.style.background = 'white'; });
      btn.addEventListener('click', function () {
        if (!childSel.value) { alert('Pick a child first.'); return; }
        openDetailsModal(t, parseInt(childSel.value, 10));
      });
      grid.appendChild(btn);
    });
    wrap.appendChild(grid);

    var recentWrap = Dom.el('div', { style: 'background:white;border-radius:14px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);' });
    recentWrap.appendChild(Dom.el('h3', { style: 'margin:0 0 12px;font-size:13px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, 'Today\'s log'));
    var recent = Dom.el('div'); recentWrap.appendChild(recent);
    wrap.appendChild(recentWrap);

    // Load child list — parent sees their kids, staff sees their centre's kids
    Api.get('/parent/children').then(function (r) {
      var kids = (r && r.children) || [];
      childSel.innerHTML = '';
      if (!kids.length) {
        // Staff fallback — agency-wide children
        return Api.get('/admin/children').then(function (r2) {
          (r2.children || []).forEach(function (c) {
            childSel.appendChild(Dom.el('option', { value: c.id }, (c.first_name + ' ' + c.last_name) + ' · ' + (c.centre_name || '')));
          });
          if (childSel.options.length) loadRecent();
        }).catch(function () {
          childSel.innerHTML = '<option value="">No children loaded</option>';
        });
      }
      kids.forEach(function (c) { childSel.appendChild(Dom.el('option', { value: c.id }, c.first_name + ' ' + c.last_name)); });
      loadRecent();
    }).catch(function () {
      childSel.innerHTML = '<option value="">Sign in failed</option>';
    });
    childSel.addEventListener('change', loadRecent);

    function loadRecent() {
      if (!childSel.value) return;
      Api.get('/care/logs/child/' + childSel.value).then(function (data) {
        Dom.clear(recent);
        var todayLogs = (data.logs || []).filter(function (l) {
          return new Date(l.occurred_at) >= new Date(new Date().setHours(0,0,0,0));
        });
        if (!todayLogs.length) {
          recent.appendChild(Dom.el('div', { style: 'padding:24px;color:#9CA3AF;font-size:13px;text-align:center;' }, 'Nothing logged today yet.'));
          return;
        }
        todayLogs.forEach(function (l) {
          var tInfo = TYPES.find(function (t) { return t.type === l.log_type; }) || { icon: '•', color: '#6B7280' };
          var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #F3F4F6;' });
          row.appendChild(Dom.el('div', { style: 'font-size:24px;width:32px;text-align:center;' }, tInfo.icon));
          var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
          var head = Dom.el('div', { style: 'font-weight:700;font-size:14px;color:#0F172A;' });
          head.textContent = l.log_type.charAt(0).toUpperCase() + l.log_type.slice(1);
          if (l.details) head.textContent += ' — ' + l.details;
          body.appendChild(head);
          body.appendChild(Dom.el('div', { style: 'font-size:11px;color:#9CA3AF;' }, fmt(l.occurred_at) + ' · by ' + (l.logged_by || 'staff')));
          if (l.notes) body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-top:2px;' }, l.notes));
          row.appendChild(body);
          recent.appendChild(row);
        });
      });
    }

    function openDetailsModal(t, childId) {
      var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;' });
      var modal = Dom.el('div', { style: 'background:white;border-radius:14px;max-width:420px;width:100%;padding:24px;' });
      overlay.appendChild(modal);
      modal.innerHTML = '<h2 style="margin:0 0 14px;font-size:18px;">' + t.icon + ' ' + t.label + '</h2>';
      var detailsIn = Dom.el('input', { type: 'text', placeholder: 'Detail (e.g. wet only, lunch, etc.)', style: 'width:100%;padding:10px;border:1px solid #D1D5DB;border-radius:10px;font-size:14px;margin-bottom:10px;box-sizing:border-box;' });
      var notesIn = Dom.el('textarea', { placeholder: 'Notes (optional)', style: 'width:100%;padding:10px;border:1px solid #D1D5DB;border-radius:10px;font-size:14px;min-height:70px;box-sizing:border-box;font-family:inherit;' });
      modal.appendChild(detailsIn); modal.appendChild(notesIn);
      var amtWrap;
      if (t.type === 'bottle') {
        amtWrap = Dom.el('div', { style: 'display:flex;gap:8px;margin-top:8px;align-items:center;' });
        amtWrap.appendChild(Dom.el('label', { style: 'font-size:13px;color:#6B7280;' }, 'oz:'));
        var amtIn = Dom.el('input', { type: 'number', step: '0.5', placeholder: '4', style: 'width:80px;padding:8px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;' });
        amtWrap.appendChild(amtIn);
        modal.appendChild(amtWrap);
      }
      var actions = Dom.el('div', { style: 'display:flex;justify-content:flex-end;gap:8px;margin-top:14px;' });
      var cancel = Dom.el('button', { style: 'background:white;border:1px solid #D1D5DB;padding:9px 16px;border-radius:8px;cursor:pointer;font-size:13px;' }, 'Cancel');
      cancel.addEventListener('click', function () { overlay.remove(); });
      var save = Dom.el('button', { style: 'background:' + t.color + ';color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;' }, 'Log it');
      save.addEventListener('click', function () {
        var body = { child_id: childId, log_type: t.type, details: detailsIn.value.trim() || null, notes: notesIn.value.trim() || null };
        if (t.type === 'bottle' && amtIn && amtIn.value) body.amount_oz = parseFloat(amtIn.value);
        save.disabled = true; save.textContent = 'Saving…';
        Api.post('/care/logs', body).then(function () { overlay.remove(); loadRecent(); }).catch(function (e) {
          alert('Failed: ' + e.message); save.disabled = false; save.textContent = 'Log it';
        });
      });
      actions.appendChild(cancel); actions.appendChild(save);
      modal.appendChild(actions);
      document.body.appendChild(overlay);
    }
  }

  // ─── Portfolio — chronological observations + milestone progress ──
  function renderPortfolio(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);
    var childId = paramId();
    if (!childId) { wrap.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:24px;' }, 'Missing child id — open from a child detail page.')); return; }

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#7C3AED 0%,#1F6080 60%,#16637A 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📚 PORTFOLIO</div><h1>Learning journey</h1><div class="kt-hero-sub">Every observation, photo, and milestone — in one chronological view.</div>';
    wrap.appendChild(hero);

    var body = Dom.el('div', { style: 'margin-top:18px;' });
    wrap.appendChild(body);
    body.appendChild(Dom.el('div', { style: 'padding:32px;text-align:center;color:#9CA3AF;' }, 'Loading…'));

    Api.get('/care/portfolio/' + childId).then(function (data) {
      Dom.clear(body);
      var c = data.child || {};
      var st = data.stats || {};

      var head = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:14px;display:flex;align-items:center;gap:18px;' });
      head.appendChild(Dom.el('div', { style: 'width:64px;height:64px;border-radius:50%;background:#1F6080;color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:24px;' }, (c.preferred_name || c.first_name || '?').charAt(0).toUpperCase()));
      var headBody = Dom.el('div');
      headBody.appendChild(Dom.el('div', { style: 'font-size:20px;font-weight:800;color:#0F172A;' }, (c.preferred_name || c.first_name) + ' ' + c.last_name));
      headBody.appendChild(Dom.el('div', { style: 'font-size:13px;color:#6B7280;' }, c.date_of_birth ? ('Born ' + c.date_of_birth) : ''));
      head.appendChild(headBody);
      body.appendChild(head);

      var stats = Dom.el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px;' });
      function tile(label, value, color) {
        var t = Dom.el('div', { style: 'background:white;padding:14px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);border-left:4px solid ' + color + ';' });
        t.appendChild(Dom.el('div', { style: 'font-size:11px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, label));
        t.appendChild(Dom.el('div', { style: 'font-size:24px;font-weight:800;color:#0F172A;' }, String(value)));
        return t;
      }
      stats.appendChild(tile('Observations', st.observations || 0, '#7C3AED'));
      stats.appendChild(tile('Milestones achieved', st.milestones_achieved || 0, '#16A34A'));
      stats.appendChild(tile('In progress', st.milestones_in_progress || 0, '#F59E0B'));
      stats.appendChild(tile('Emerging', st.milestones_emerging || 0, '#1F6080'));
      body.appendChild(stats);

      // Timeline of observations
      var feed = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05);' });
      feed.appendChild(Dom.el('h3', { style: 'margin:0 0 14px;font-size:13px;font-weight:700;color:#6B7280;letter-spacing:1px;text-transform:uppercase;' }, 'Observation timeline'));
      if (!data.observations.length) {
        feed.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#9CA3AF;' }, 'No observations recorded yet.'));
      } else {
        data.observations.forEach(function (o) {
          var item = Dom.el('div', { style: 'border-left:3px solid #7C3AED;padding:6px 14px;margin-bottom:14px;background:#F8FAFC;border-radius:0 10px 10px 0;' });
          item.appendChild(Dom.el('div', { style: 'font-size:11px;color:#6B7280;font-weight:700;letter-spacing:.5px;' }, fmt(o.observed_at) + ' · ' + o.framework + ' / ' + o.domain + ' · ' + o.recorded_by));
          if (o.title) item.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;color:#0F172A;margin-top:3px;' }, o.title));
          item.appendChild(Dom.el('div', { style: 'font-size:13px;color:#374151;margin-top:3px;line-height:1.5;' }, o.body || ''));
          feed.appendChild(item);
        });
      }
      body.appendChild(feed);
    }).catch(function (e) {
      Dom.clear(body);
      body.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:24px;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  // ─── Milestones checklist ─────────────────────────────────────────
  function renderMilestones(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);
    var childId = paramId();
    if (!childId) { wrap.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:24px;' }, 'Missing child id.')); return; }

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#16A34A 0%,#7C3AED 60%,#1F6080 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">🌱 MILESTONES</div><h1>Developmental milestones</h1><div class="kt-hero-sub">Tap each milestone to mark it Emerging, In progress, or Achieved. Aligned to HDLH domains.</div>';
    wrap.appendChild(hero);

    var body = Dom.el('div'); wrap.appendChild(body);
    body.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#9CA3AF;' }, 'Loading…'));

    Promise.all([
      Api.get('/care/milestones/catalog'),
      Api.get('/care/milestones/child/' + childId),
    ]).then(function (results) {
      var catalog = results[0].catalog;
      var records = results[1].records || {};
      Dom.clear(body);

      Object.keys(catalog).forEach(function (band) {
        var bandWrap = Dom.el('div', { style: 'background:white;border-radius:14px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-top:14px;' });
        bandWrap.appendChild(Dom.el('h3', { style: 'margin:0 0 4px;font-size:18px;color:#0F172A;text-transform:capitalize;' }, band));
        bandWrap.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;margin-bottom:12px;' }, 'Age band'));
        Object.keys(catalog[band]).forEach(function (domain) {
          bandWrap.appendChild(Dom.el('h4', { style: 'margin:14px 0 6px;font-size:12px;color:#7C3AED;font-weight:700;letter-spacing:1px;text-transform:uppercase;' }, domain));
          catalog[band][domain].forEach(function (m) {
            var current = records[m.key] ? records[m.key].status : 'emerging';
            var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #F3F4F6;' });
            row.appendChild(Dom.el('div', { style: 'flex:1;font-size:13px;color:#0F172A;' }, m.label));
            ['emerging','in_progress','achieved'].forEach(function (s) {
              var colors = { emerging: '#9CA3AF', in_progress: '#F59E0B', achieved: '#16A34A' };
              var b = Dom.el('button', {
                style: 'background:' + (current === s ? colors[s] : 'white') + ';color:' + (current === s ? 'white' : '#6B7280') + ';border:1px solid ' + (current === s ? colors[s] : '#E5E7EB') + ';padding:5px 10px;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600;text-transform:capitalize;',
              }, s.replace('_',' '));
              b.addEventListener('click', function () {
                Api.post('/care/milestones', {
                  child_id: childId, framework: 'HDLH', domain: domain,
                  milestone_key: m.key, milestone_label: m.label,
                  status: s, observed_at: new Date().toISOString().slice(0,10),
                }).then(function () { renderMilestones(container); }).catch(function (e) { alert('Failed: ' + e.message); });
              });
              row.appendChild(b);
            });
            bandWrap.appendChild(row);
          });
        });
        body.appendChild(bandWrap);
      });
    });
  }

  // ─── Tour bookings (admin view) ───────────────────────────────────
  function renderTours(container) {
    Dom.clear(container);
    // v22p75.2: fully self-contained inline styling — NO data-kt-pretty and NO
    // design-system classes (.kt-card/.kt-kpi/.kt-btn), which double-applied from
    // v22p55 + v22p72 CSS and broke the layout. Everything below is explicit.
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { style: 'background:linear-gradient(135deg,#FF8A65 0%,#7C3AED 60%,#1F6080 100%);color:#fff;border-radius:16px;padding:26px 30px;box-shadow:0 10px 30px -12px rgba(31,96,128,.45);' });
    hero.innerHTML = '<div style="font-size:12px;font-weight:800;letter-spacing:1.5px;opacity:.85;">🚪 TOURS</div>'
      + '<h1 style="margin:6px 0 6px;font-size:26px;font-weight:800;">Tour bookings</h1>'
      + '<div style="font-size:14px;opacity:.92;max-width:640px;">Prospective families that requested a centre tour through your public booking page. Confirm, complete, or cancel each one.</div>';
    wrap.appendChild(hero);

    // Public booking link card
    var agencyId = sessionStorage.getItem('kt_active_agency_id') || '';
    var publicUrl = (window.location.origin || 'https://app.kiddietrac.com') + '/book-tour.html' + (agencyId ? ('?agency=' + agencyId) : '');
    var cardStyle = 'background:#fff;border:1px solid #EAEFF3;border-radius:14px;box-shadow:0 1px 2px rgba(16,40,64,.04),0 6px 16px -8px rgba(16,40,64,.12);';
    var linkCard = Dom.el('div', { style: cardStyle + 'margin-top:18px;padding:16px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;' });
    linkCard.appendChild(Dom.el('span', { style: 'font-size:13px;font-weight:700;color:#1F6080;flex-shrink:0;' }, '🔗 Public booking link'));
    var linkInput = Dom.el('input', { readonly: 'readonly', value: publicUrl, style: 'flex:1;min-width:220px;padding:9px 12px;border:1.5px solid #E2E8F0;border-radius:8px;font-family:monospace;font-size:12px;background:#F8FAFC;box-sizing:border-box;' });
    linkInput.addEventListener('click', function () { linkInput.select(); });
    linkCard.appendChild(linkInput);
    var copyBtn = Dom.el('button', { style: 'flex-shrink:0;font-size:13px;font-weight:700;padding:9px 16px;border:none;border-radius:8px;cursor:pointer;background:#1F6080;color:#fff;' }, 'Copy');
    copyBtn.addEventListener('click', function () {
      try { navigator.clipboard.writeText(publicUrl); copyBtn.textContent = '✓ Copied'; setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1400); } catch (e) {}
    });
    linkCard.appendChild(copyBtn);
    var csvBtn = Dom.el('button', { style: 'flex-shrink:0;font-size:13px;font-weight:700;padding:9px 16px;border:none;border-radius:8px;cursor:pointer;background:#F1F5F9;color:#1F2937;' }, '⤓ Export CSV');
    linkCard.appendChild(csvBtn);
    wrap.appendChild(linkCard);

    // Summary KPI row (explicit grid + tiles)
    var kpiRow = Dom.el('div', { style: 'margin-top:18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;' });
    wrap.appendChild(kpiRow);

    // Status filter
    var filterBar = Dom.el('div', { style: 'margin-top:18px;display:flex;gap:8px;flex-wrap:wrap;' });
    wrap.appendChild(filterBar);

    var listWrap = Dom.el('div', { style: cardStyle + 'overflow:hidden;margin-top:14px;' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:30px;text-align:center;color:#9CA3AF;' }, 'Loading…'));

    var allTours = [];
    var activeFilter = '';

    function kpi(label, value, color) {
      var k = Dom.el('div', { style: cardStyle + 'padding:16px 18px;' });
      k.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:12px;font-weight:600;' }, label));
      k.appendChild(Dom.el('div', { style: 'font-size:26px;font-weight:800;line-height:1.1;margin-top:4px;color:' + (color || '#111827') + ';' }, String(value)));
      return k;
    }

    function drawFilterBar() {
      Dom.clear(filterBar);
      [['', 'All'], ['requested', 'Requested'], ['confirmed', 'Confirmed'], ['completed', 'Completed'], ['no_show', 'No-show'], ['cancelled', 'Cancelled']].forEach(function (f) {
        var fval = f[0];
        var on = (activeFilter === fval);
        var chip = Dom.el('button', {
          style: 'font-size:12px;font-weight:600;padding:7px 15px;border-radius:18px;cursor:pointer;border:1px solid ' + (on ? '#1F6080' : '#E2E8F0') + ';' + (on ? 'background:#1F6080;color:#fff;' : 'background:#fff;color:#4B5563;')
        }, f[1]);
        chip.addEventListener('click', function () { activeFilter = fval; drawFilterBar(); drawList(); });
        filterBar.appendChild(chip);
      });
    }

    function drawList() {
      Dom.clear(listWrap);
      var rows = activeFilter ? allTours.filter(function (t) { return t.status === activeFilter; }) : allTours;
      if (!rows.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, activeFilter ? ('No ' + activeFilter.replace('_', ' ') + ' tours.') : 'No tour requests yet. Share your public link above to start collecting bookings.'));
        return;
      }
      rows.forEach(function (t) { listWrap.appendChild(renderTourRow(t, container)); });
    }

    Api.get('/admin/tours').then(function (data) {
      allTours = (data && data.tours) || [];
      // KPIs
      Dom.clear(kpiRow);
      var counts = { requested: 0, confirmed: 0, completed: 0, no_show: 0, cancelled: 0 };
      allTours.forEach(function (t) { if (counts[t.status] != null) counts[t.status]++; });
      kpiRow.appendChild(kpi('Total', allTours.length, '#1F6080'));
      kpiRow.appendChild(kpi('Requested', counts.requested, '#F59E0B'));
      kpiRow.appendChild(kpi('Confirmed', counts.confirmed, '#1F6080'));
      kpiRow.appendChild(kpi('Completed', counts.completed, '#16A34A'));
      kpiRow.appendChild(kpi('No-show', counts.no_show, '#DC2626'));
      drawFilterBar();
      drawList();
    }).catch(function (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'color:#DC2626;padding:24px;' }, 'Failed: ' + (e.message || 'error')));
    });

    csvBtn.addEventListener('click', function () {
      if (!allTours.length) return;
      var head = ['Parent', 'Email', 'Phone', 'Centre', 'Tour at', 'Preferred start', 'Child age (mo)', 'Status', 'Notes'];
      var lines = [head.join(',')];
      allTours.forEach(function (t) {
        var cells = [t.parent_name, t.parent_email, t.parent_phone || '', t.centre_name || '', t.tour_at || '', t.preferred_start_date || '', t.child_age_months || '', t.status, (t.notes || '').replace(/\n/g, ' ')];
        lines.push(cells.map(function (c) { return '"' + String(c == null ? '' : c).replace(/"/g, '""') + '"'; }).join(','));
      });
      var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'tour-bookings.csv';
      a.click();
    });
  }

  function renderTourRow(t, container) {
    var statusColors = { requested: '#F59E0B', confirmed: '#1F6080', completed: '#16A34A', no_show: '#DC2626', cancelled: '#6B7280' };
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;' });
    row.appendChild(Dom.el('div', { style: 'width:8px;height:48px;border-radius:4px;background:' + (statusColors[t.status] || '#E5E7EB') + ';flex-shrink:0;' }));
    var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
    body.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:14px;color:#111827;' }, (t.parent_name || 'Unnamed') + ' · ' + (t.centre_name || '—')));
    body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, 'Tour ' + fmt(t.tour_at) + ' · ' + (t.parent_email || 'no email') + (t.parent_phone ? ' · ' + t.parent_phone : '') + (t.child_age_months ? ' · child ' + t.child_age_months + ' mo' : '')));
    if (t.preferred_start_date) body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#1F6080;margin-top:2px;font-weight:600;' }, '📅 Wants to start: ' + fmtDateOnly(t.preferred_start_date)));
    if (t.notes) body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#374151;margin-top:4px;' }, '“' + t.notes + '”'));
    row.appendChild(body);
    var sel = Dom.el('select', { style: 'padding:6px 8px;border:1px solid #D1D5DB;border-radius:8px;font-size:12px;background:white;' });
    ['requested','confirmed','completed','no_show','cancelled'].forEach(function (s) {
      var opt = Dom.el('option', { value: s }, s.replace('_',' '));
      if (s === t.status) opt.selected = true;
      sel.appendChild(opt);
    });
    sel.addEventListener('change', function () {
      Api.patch('/admin/tours/' + t.id, { status: sel.value }).then(function () { renderTours(container); });
    });
    row.appendChild(sel);
    return row;
  }

  // ─── Shell registration ───────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    ['agency_admin','centre_director','educator'].forEach(function (r) {
      Shell.registerScreen(r + ':time-clock', renderTimeClock);
      Shell.registerScreen(r + ':care-log',   renderCareLog);
    });
    Shell.registerScreen('guardian:care-log', renderCareLog);
    ['agency_admin','centre_director','educator','guardian'].forEach(function (r) {
      Shell.registerScreen(r + ':portfolio',  renderPortfolio);
      Shell.registerScreen(r + ':milestones', renderMilestones);
    });
    ['agency_admin','centre_director','platform_admin'].forEach(function (r) {
      Shell.registerScreen(r + ':tours', renderTours);
    });
  }
  KT.Care = {
    renderTimeClock: renderTimeClock,
    renderCareLog: renderCareLog,
    renderPortfolio: renderPortfolio,
    renderMilestones: renderMilestones,
    renderTours: renderTours,
  };
})(window);
