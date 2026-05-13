/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v14 — Navigation additions + unread badge
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function getRole() { const u = getUser(); return u.primary_role || (u.roles && u.roles[0]) || 'guest'; }
  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }

  function findNav() {
    return document.querySelector('.app-nav')
        || document.querySelector('.kt-sidebar')
        || document.querySelector('[class*="sidebar"]')
        || document.querySelector('nav');
  }
  function getMain() {
    return document.querySelector('.kt-main') || document.querySelector('main') || document.querySelector('#main') || document.body;
  }

  function injectNav() {
    if (window.KT_V17_NAV_INSTALLED) return; // v17: nav owned by app-v2-shell
    const sidebar = findNav();
    if (!sidebar) { setTimeout(injectNav, 300); return; }
    const role = getRole();
    if (role === 'guest') return;

    let extra = document.getElementById('kt-v14-nav-extra');
    if (!extra) {
      extra = document.createElement('div');
      extra.id = 'kt-v14-nav-extra';
      extra.style.cssText = 'display:flex;flex-direction:column;gap:2px;padding:4px 0;';
      sidebar.appendChild(extra);
    }
    if (extra.querySelector('[data-route="lesson-plans"]')) return;

    if (role === 'educator' || role === 'centre_director' || role === 'agency_admin') {
      extra.appendChild(makeNavBtn('lesson-plans', '📚 Lesson Plans'));
    }
    if (role === 'centre_director' || role === 'agency_admin') {
      extra.appendChild(makeNavBtn('schedule', '📅 Schedule'));
      extra.appendChild(makeNavBtn('certifications', '🎓 Certifications'));
      extra.appendChild(makeNavBtn('timesheets', '📊 Timesheets'));
    }
    if (role === 'guardian') {
      extra.appendChild(makeNavBtn('lesson-plans', '📚 This Week'));
    }
  }

  function makeNavBtn(routeId, label) {
    const btn = document.createElement('a');
    btn.setAttribute('data-route', routeId);
    btn.href = '#' + routeId;
    btn.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:10px 16px;color:#374151;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;margin:2px 8px;cursor:pointer;';
    btn.innerHTML = '<span>' + label + '</span>';
    btn.addEventListener('mouseenter', () => btn.style.background = '#F3F4F6');
    btn.addEventListener('mouseleave', () => btn.style.background = 'transparent');
    btn.addEventListener('click', (e) => { e.preventDefault(); window.location.hash = '#' + routeId; route(); });
    return btn;
  }

  function route() {
    if (window.KT_V17_NAV_INSTALLED) return; // v21.1: v17 shell owns routing now
    const hash = (window.location.hash.replace('#', '').toLowerCase().split('/')[0]) || '';
    const c = getMain();
    if (!c) return;

    if (hash === 'lesson-plans' && window.KT && window.KT.LessonPlans) return window.KT.LessonPlans.render(c);
    if (hash === 'schedule' && window.KT && window.KT.Schedule) return window.KT.Schedule.render(c);
    if (hash === 'certifications' && window.KT && window.KT.Certifications) return window.KT.Certifications.render(c);
    if (hash === 'timesheets' && window.KT && window.KT.Timesheets) return window.KT.Timesheets.render(c);

    // When opening announcements inbox, mark as read so badge clears
    if (hash === 'announcements') {
      fetch(apiBase() + '/notifications/mark-read', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'announcement' }),
      }).catch(() => {}).finally(updateAnnouncementBadge);
    }
  }

  // ─── ANNOUNCEMENT UNREAD BADGE ──────────────────────────────────
  async function updateAnnouncementBadge() {
    if (!token()) return;
    try {
      const r = await fetch(apiBase() + '/notifications/unread-count?type=announcement', {
        headers: { 'Authorization': 'Bearer ' + token() }
      });
      if (!r.ok) return;
      const data = await r.json();
      setBadge('announcements', data.unread || 0);
    } catch (e) {}
  }

  function setBadge(route, count) {
    document.querySelectorAll('a[data-route="' + route + '"], a[href="#' + route + '"]').forEach(a => {
      let badge = a.querySelector('.kt-unread-badge');
      if (count > 0) {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'kt-unread-badge';
          badge.style.cssText = 'background:#DC2626;color:white;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:700;min-width:18px;text-align:center;';
          a.appendChild(badge);
        }
        badge.textContent = count > 99 ? '99+' : count;
      } else if (badge) {
        badge.remove();
      }
    });
  }

  function init() {
    if (!token()) return;
    injectNav();
    route();
    updateAnnouncementBadge();
    window.addEventListener('hashchange', route);
    // Poll every 30 sec
    setInterval(updateAnnouncementBadge, 30000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  window.addEventListener('storage', (e) => { if (e.key === 'kt_token') setTimeout(init, 200); });
})(window);
