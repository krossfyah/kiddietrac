/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v2 — App Shell
   Common router, modal system, role detection
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  // ─── v17.3: Suppress legacy nav-additions IMMEDIATELY ─────────
  // The v12/v13/v14/v15 nav-additions files run on DOMContentLoaded
  // and inject their own <div id="kt-v##-nav-extra"> elements as
  // siblings of #navLinks. If they have the guard we shipped in v17
  // (an early-return checking this flag), they bail. Setting the flag
  // at IIFE-load time guarantees the guard sees it set regardless of
  // script execution order.
  window.KT_V17_NAV_INSTALLED = true;

  const { Auth, Api, Fmt, Dom } = window.KT;

  // ──────────────── Role detection ─────────────────────────────
  const Roles = {
    primaryRoleOf(user) {
      if (!user || !user.roles) return null;
      // v22p39: platform_admin gets routed through the agency_admin shell
      // so a user who holds ONLY the platform role still gets a nav and
      // dashboard rendered. Previously primary_role came back as null and
      // the shell hung on 'Loading your workspace…' indefinitely. The
      // platform-overview + all-agencies links are injected separately
      // by agency-switcher.js, so nothing is lost.
      if (user.roles.includes('agency_admin'))    return 'agency_admin';
      if (user.roles.includes('platform_admin'))  return 'agency_admin';
      if (user.roles.includes('centre_director')) return 'centre_director';
      if (user.roles.includes('educator'))        return 'educator';
      if (user.roles.includes('guardian'))        return 'guardian';
      if (user.roles.includes('auditor'))         return 'auditor';
      return null;
    },

    isStaff(user) {
      const r = this.primaryRoleOf(user);
      return r === 'agency_admin' || r === 'centre_director' || r === 'educator';
    },

    isDirector(user) {
      const r = this.primaryRoleOf(user);
      return r === 'agency_admin' || r === 'centre_director';
    },

    isEducator(user) {
      return this.primaryRoleOf(user) === 'educator';
    },

    isGuardian(user) {
      return this.primaryRoleOf(user) === 'guardian';
    },
  };

  // ──────────────── Nav builder (v17 — grouped sidebar) ────────
  // Each section: { label, items: [{ hash, label, icon, badgeKey? }] }
  function navItemsForRole(role) {
    if (role === 'agency_admin') {
      // v22p34: hide legacy 'Agencies' entry for callers who are ALSO platform_admin
      // — they already get a richer 'All agencies' link from agency-switcher.js,
      // and the legacy '/admin/agencies' route shows essentially the same data
      // with fewer SaaS-level controls (no suspend/resume, no white-label edit).
      var u_v22p34 = (function () { try { return JSON.parse(sessionStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } })();
      var isPlatformAdmin_v22p34 = Array.isArray(u_v22p34.roles) && u_v22p34.roles.indexOf('platform_admin') !== -1;
      var overviewItems = [{ hash: 'dashboard', label: 'Agency overview', icon: '🏠' }];
      if (!isPlatformAdmin_v22p34) overviewItems.push({ hash: 'agencies', label: 'Agencies', icon: '🏢' });
      return [
        { label: 'Overview', items: overviewItems },
        { label: 'Operations', items: [
          { hash: 'chat',           label: 'Messages',         icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'announcements',  label: 'Announcements',    icon: '📢', badgeKey: 'announcement_unread' },
          { hash: 'lesson-plans',   label: 'Lesson plans',     icon: '📚' },
          { hash: 'observations',   label: 'Observations',     icon: '\ud83d\udc40' },
          { hash: 'lesson-plans-ai', label: 'AI Lesson Plans',  icon: '\ud83d\udcd6' },
          { hash: 'schedule',       label: 'Schedule',         icon: '📅' },
          { hash: 'certifications', label: 'Certifications',   icon: '🎓' },
          { hash: 'timesheets',     label: 'Timesheets',       icon: '📊' },
          { hash: 'waitlist',       label: 'Waitlist',         icon: '⏳' },
          { hash: 'incidents',     label: 'Incidents',        icon: '⚠️' },
          { hash: 'medications',   label: 'Medications',      icon: '💊' },
          { hash: 'immunizations', label: 'Immunizations',    icon: '🩹' },
        ]},
        { label: 'Growth', items: [
          { hash: 'marketing-campaigns', label: 'Marketing',   icon: '📣' },
        ]},
        { label: 'Staff', items: [
          { hash: 'staff-calendar',  label: 'Calendar',         icon: '📅' },
        ]},
        { label: 'Reseller', items: [
          { hash: 'admin-mrr',      label: 'MRR dashboard',    icon: '💰' },
          { hash: 'admin-features', label: 'Feature flags',    icon: '⚙️' },
          { hash: 'admin-branding', label: 'Branding',         icon: '🎨' },
          { hash: 'digest-status', label: 'AI digest status', icon: '🤖' },
        ]},
        { label: 'Enrollment', items: [
          { hash: 'invitation-codes', label: 'Invitation codes', icon: '✉️' },
          { hash: 'edocuments',       label: 'eDocuments',       icon: '📄' },
        ]},
        { label: 'Administration', items: [
          { hash: 'admin-users',      label: 'User management',  icon: '👥' },
          { hash: 'admin-centres',    label: 'Centres',          icon: '🏫' },
          { hash: 'admin-families',   label: 'Families',         icon: '👪' },
          { hash: 'admin-children',   label: 'Children',         icon: '🧒' },
          { hash: 'admin-billing',    label: 'Billing (Stripe)', icon: '💳' },
          { hash: 'bulk-invoices',    label: 'Bulk invoice run', icon: '💸' },
          { hash: 'audit-logs',       label: 'Audit log',        icon: '📜' },
        ]},
        { label: 'Settings', items: [
          { hash: 'admin-roles',        label: 'Roles & permissions', icon: '🛡' },
          { hash: 'sibling-discounts',  label: 'Sibling discounts',   icon: '👨‍👩‍👧' },
          { hash: 'mfa',                label: 'Two-factor (MFA)',    icon: '🔐' },
          { hash: 'help',               label: 'Help & guides',       icon: '📖' },
        ]},
      ];
    }
    if (role === 'centre_director') {
      return [
        { label: 'Overview', items: [
          { hash: 'dashboard',      label: 'Dashboard',        icon: '🏠' },
          { hash: 'children',       label: 'Children',         icon: '🧒' },
          { hash: 'families',       label: 'Families',         icon: '👨‍👩‍👧' },
          { hash: 'staff',          label: 'Staff',            icon: '👥' },
        ]},
        { label: 'Operations', items: [
          { hash: 'today',          label: 'Today',            icon: '✨' },
          { hash: 'chat',           label: 'Messages',         icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'announcements',  label: 'Announcements',    icon: '📢', badgeKey: 'announcement_unread' },
          { hash: 'lesson-plans',   label: 'Lesson plans',     icon: '📚' },
          { hash: 'observations',   label: 'Observations',     icon: '\ud83d\udc40' },
          { hash: 'lesson-plans-ai', label: 'AI Lesson Plans',  icon: '\ud83d\udcd6' },
          { hash: 'schedule',       label: 'Schedule',         icon: '📅' },
          { hash: 'certifications', label: 'Certifications',   icon: '🎓' },
          { hash: 'timesheets',     label: 'Timesheets',       icon: '📊' },
          { hash: 'waitlist',       label: 'Waitlist',         icon: '⏳' },
          { hash: 'incidents',     label: 'Incidents',        icon: '⚠️' },
          { hash: 'medications',   label: 'Medications',      icon: '💊' },
          { hash: 'immunizations', label: 'Immunizations',    icon: '🩹' },
          { hash: 'digest-status', label: 'AI digest status', icon: '🤖' },
        ]},
        { label: 'Growth', items: [
          { hash: 'marketing-campaigns', label: 'Marketing',  icon: '📣' },
        ]},
        { label: 'Staff', items: [
          { hash: 'staff-calendar', label: 'Calendar',         icon: '📅' },
        ]},
        { label: 'Enrollment', items: [
          { hash: 'invitation-codes', label: 'Invitation codes', icon: '✉️' },
          { hash: 'edocuments',       label: 'eDocuments',       icon: '📄' },
        ]},
        { label: 'Settings', items: [
          { hash: 'mfa',              label: 'Two-factor (MFA)', icon: '🔐' },
          { hash: 'help',             label: 'Help & guides',    icon: '📖' },
        ]},
      ];
    }
    if (role === 'educator') {
      return [
        { label: 'Classroom', items: [
          { hash: 'today',          label: 'Today',            icon: '✨' },
          { hash: 'lesson-plans',   label: 'Lesson plans',     icon: '📚' },
          { hash: 'observations',   label: 'Observations',     icon: '\ud83d\udc40' },
          { hash: 'announcements',  label: 'Announcements',    icon: '📢' },
          { hash: 'chat',           label: 'Messages',         icon: '💬', badgeKey: 'chat_unread' },
          { hash: 'incidents',     label: 'Incidents',        icon: '⚠️' },
          { hash: 'medications',   label: 'Medications',      icon: '💊' },
        ]},
        { label: 'Settings', items: [
          { hash: 'mfa',           label: 'Two-factor (MFA)', icon: '🔐' },
          { hash: 'help',          label: 'Help & guides',    icon: '📖' },
        ]},
      ];
    }
    // guardian (default)
    return [
      { label: 'Your child', items: [
        { hash: 'today',          label: 'Today',          icon: '✨' },
        { hash: 'photos',         label: 'Photos',         icon: '📸' },
        { hash: 'lesson-plans',   label: 'This week',      icon: '📚' },
        { hash: 'messages',       label: 'Messages',       icon: '💬', badgeKey: 'chat_unread' },
      ]},
      { label: 'Account', items: [
        { hash: 'billing',        label: 'Billing',        icon: '💳' },
        { hash: 'autopay',        label: 'Autopay',        icon: '🔁' },
        { hash: 'announcements',  label: 'Announcements',  icon: '📢' },
        { hash: 'incidents',     label: 'Incidents',      icon: '⚠️' },
        { hash: 'medications',   label: 'Medications',    icon: '💊' },
        { hash: 'immunizations', label: 'Immunizations',  icon: '🩹' },
        { hash: 'edocuments',    label: 'Documents',      icon: '📄' },
      ]},
      { label: 'Settings', items: [
        { hash: 'mfa',           label: 'Two-factor (MFA)', icon: '🔐' },
        { hash: 'help',          label: 'Help & guides',    icon: '📖' },
      ]},
    ];
  }

  function buildNav(user) {
    const links = Dom.$('#navLinks');
    if (!links) return;
    Dom.clear(links);

    const role = Roles.primaryRoleOf(user);
    const sections = navItemsForRole(role);
    const isSidebar = (role === 'agency_admin' || role === 'centre_director');

    if (isSidebar) {
      // Grouped sidebar layout (sections with labels)
      sections.forEach(section => {
        const sectionEl = Dom.el('div', { class: 'sidebar-section' });
        if (section.label) {
          sectionEl.appendChild(Dom.el('div', { class: 'sidebar-section-label' }, section.label));
        }
        section.items.forEach(item => sectionEl.appendChild(buildNavLink(item)));
        links.appendChild(sectionEl);
      });
    } else {
      // Flat horizontal list for top-nav layout (educator + guardian)
      sections.forEach(section => {
        section.items.forEach(item => links.appendChild(buildNavLink(item)));
      });
    }

    // Mark that v17 buildNav has run — nav-additions-v12/13/14/15 see this
    // flag and skip their old injectNav() side-effects.
    window.KT_V17_NAV_INSTALLED = true;

    updateActiveNav();
  }

  function buildNavLink(item) {
    const a = Dom.el('a', {
      href: '#' + item.hash,
      class: 'nav-link',
      'data-hash': item.hash,
    });
    if (item.icon) {
      a.appendChild(Dom.el('span', { class: 'nav-icon' }, item.icon));
    }
    a.appendChild(Dom.el('span', { class: 'nav-label' }, item.label));
    if (item.badgeKey) {
      a.appendChild(Dom.el('span', {
        class: 'nav-badge',
        'data-badge-key': item.badgeKey,
        style: 'display:none;',
      }, ''));
    }
    return a;
  }

  // ──────────────── Badge updater ──────────────────────────────
  // External code (push-client, chat polling, announcement polling)
  // can call KT.Shell.setBadge('chat_unread', 4) to update a count.
  function setBadge(key, count) {
    document.querySelectorAll('[data-badge-key="' + key + '"]').forEach(el => {
      if (count > 0) {
        el.textContent = count > 99 ? '99+' : String(count);
        el.style.display = '';
      } else {
        el.style.display = 'none';
      }
    });
  }

  function updateActiveNav() {
    const hash = (window.location.hash || '#dashboard').replace('#', '');
    Dom.$$('.nav-link').forEach(link => {
      const isActive = link.dataset.hash === hash || (hash === '' && link.dataset.hash === 'dashboard');
      link.classList.toggle('active', isActive);
    });
  }

  // ──────────────── Screen router ──────────────────────────────
  const screens = {}; // populated by individual screen modules

  function registerScreen(name, screenFn) {
    screens[name] = screenFn;
  }

  async function renderScreen() {
    const main = Dom.$('#appMain');
    Dom.clear(main);

    const user = Auth.user();
    const role = Roles.primaryRoleOf(user);
    const hash = (window.location.hash || '#dashboard').replace('#', '').split('?')[0];

    updateActiveNav();

    // Route based on role + hash
    const screenKey = `${role}:${hash}`;
    const fallbackKey = `${role}:dashboard`;

    const fn = screens[screenKey] || screens[fallbackKey] || screens['guardian:today'];

    if (!fn) {
      main.appendChild(emptyState('🤔', 'Screen not available', 'No screen registered for this role + page.'));
      return;
    }

    try {
      await fn(main, { user, role, params: parseParams() });
    } catch (e) {
      console.error('Screen render error:', e);
      Dom.clear(main);
      main.appendChild(emptyState('⚠️', 'Something went wrong', e.message || 'Please refresh the page.'));
    }
  }

  function parseParams() {
    const hashParts = (window.location.hash || '').split('?');
    if (hashParts.length < 2) return {};
    const params = {};
    new URLSearchParams(hashParts[1]).forEach((v, k) => { params[k] = v; });
    return params;
  }

  function navigate(hash) {
    window.location.hash = '#' + hash;
  }

  // ──────────────── Modal system ───────────────────────────────
  const Modal = {
    open({ title, body, actions = [], onClose = null, large = false }) {
      const root = Dom.$('#modalRoot');
      Dom.clear(root);

      const close = () => {
        Dom.clear(root);
        if (onClose) onClose();
      };

      const backdrop = Dom.el('div', {
        class: 'modal-backdrop',
        onClick: (e) => { if (e.target === backdrop) close(); },
      });

      const modal = Dom.el('div', { class: 'modal' + (large ? ' modal-large' : '') });

      // Header
      const header = Dom.el('div', { class: 'modal-header' });
      header.appendChild(Dom.el('h2', {}, title));
      header.appendChild(Dom.el('button', {
        class: 'modal-close',
        onClick: close,
      }, '×'));
      modal.appendChild(header);

      // Body
      const bodyEl = Dom.el('div', { class: 'modal-body' });
      if (typeof body === 'string') {
        bodyEl.innerHTML = body;
      } else if (body instanceof Node) {
        bodyEl.appendChild(body);
      }
      modal.appendChild(bodyEl);

      // Footer
      if (actions.length) {
        const footer = Dom.el('div', { class: 'modal-footer' });
        actions.forEach(a => {
          // v22p3.1: accept either `handler` (preferred) OR `onClick` (legacy from
          // screen-admin.js). Without this back-compat, every screen-admin action
          // button no-ops and the modal just closes — that's why "Create centre"
          // and "Save user" appeared to do nothing.
          const cb = a.handler || a.onClick;
          const isPrimary = a.style === 'btn-primary' || a.primary;
          const btn = Dom.el('button', {
            class: 'btn ' + (a.style || (isPrimary ? 'btn-primary' : 'btn-secondary')),
            onClick: async () => {
              if (cb) {
                btn.disabled = true;
                btn.textContent = a.busyLabel || 'Saving…';
                try {
                  const result = await cb();
                  // If the callback returned `false` (legacy onClick callers do
                  // this on validation error), leave the modal open and restore
                  // the button. Otherwise close.
                  if (result === false) {
                    btn.disabled = false;
                    btn.textContent = a.label;
                  } else {
                    close();
                  }
                } catch (err) {
                  btn.disabled = false;
                  btn.textContent = a.label;
                  if (Dom.toast) Dom.toast(err.message || 'Something went wrong', 'error');
                  else console.error(err);
                }
              } else {
                close();
              }
            },
          }, a.label);
          footer.appendChild(btn);
        });
        modal.appendChild(footer);
      }

      backdrop.appendChild(modal);
      root.appendChild(backdrop);

      return { close };
    },

    // v22p3.1: facade-level close so callers can do Shell.Modal.close() without
    // holding the handle returned by open().
    close() {
      const root = Dom.$('#modalRoot');
      if (root) Dom.clear(root);
    },

    confirm({ title, message, confirmLabel = 'Confirm', destructive = false, onConfirm }) {
      return this.open({
        title,
        body: Dom.el('p', { style: 'font-size: 15px; line-height: 1.6;' }, message),
        actions: [
          { label: 'Cancel', style: 'btn-secondary' },
          {
            label: confirmLabel,
            style: destructive ? 'btn-danger' : 'btn-primary',
            handler: onConfirm,
            busyLabel: 'Working…',
          },
        ],
      });
    },
  };

  // ──────────────── Generic helpers ────────────────────────────
  function emptyState(emoji, title, sub) {
    return Dom.el('div', { class: 'empty-large' },
      Dom.el('div', { class: 'empty-emoji' }, emoji),
      Dom.el('h3', {}, title),
      Dom.el('p', {}, sub),
    );
  }

  function statusTagFor(ratioStatus) {
    return ratioStatus === 'ok' ? { class: 'tag-success', text: 'COMPLIANT' }
      : ratioStatus === 'tight' ? { class: 'tag-warn', text: 'TIGHT' }
      : { class: 'tag-danger', text: 'BREACH' };
  }

  // ──────────────── Bootstrap ──────────────────────────────────
  async function startApp() {
    if (!Auth.requireLogin()) return;

    const user = Auth.user();
    const role = Roles.primaryRoleOf(user);

    // v17.3: Defensive cleanup — even with the early flag set, if any
    // v12/v13/v14/v15 nav-additions file is missing the guard (maybe
    // the guard patcher never ran), this observer catches injected
    // <div id="kt-v##-nav-extra"> elements and removes them as soon as
    // they're added to the sidebar. Belt-and-suspenders.
    const sidebarEl0 = Dom.$('#appSidebar');
    if (sidebarEl0 && typeof MutationObserver !== 'undefined') {
      const navObserver = new MutationObserver((mutations) => {
        for (const m of mutations) {
          for (const node of m.addedNodes) {
            if (node.nodeType === 1 && node.id && /^kt-v\d+-nav-extra$/.test(node.id)) {
              node.remove();
            }
          }
        }
      });
      navObserver.observe(sidebarEl0, { childList: true });
      // Also clean up any that snuck in before we got here
      sidebarEl0.querySelectorAll('[id^="kt-v"][id$="-nav-extra"]').forEach(n => n.remove());
    }

    // v17.2: layout class is applied in HTML by default. For non-sidebar
    // roles, we must remove BOTH the outer .app-shell--sidebar AND the
    // .app-sidebar class on the aside element — otherwise the aside's
    // vertical-column styles fight the .app-nav horizontal-row styles
    // and the top nav renders as a 100vh-tall column.
    const isSidebarRole = (role === 'agency_admin' || role === 'centre_director');
    const shell = Dom.$('#appShell');
    const sidebarEl = Dom.$('#appSidebar');
    if (isSidebarRole) {
      document.body.classList.add('layout-sidebar', 'role-' + role.replace('_', '-'));
    } else {
      if (shell)     shell.classList.remove('app-shell--sidebar');
      if (sidebarEl) sidebarEl.classList.remove('app-sidebar');
      document.body.classList.add('role-' + role.replace('_', '-'));
    }

    // Set up nav user pill
    const navAvatar = Dom.$('#navAvatar');
    const navName   = Dom.$('#navName');
    const navRole   = Dom.$('#navRole');
    if (navAvatar) {
      // v22p3.2: render photo if user.photo_url is set, fallback to initials.
      if (user && user.photo_url) {
        navAvatar.textContent = '';
        const apiBase = (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1';
        const apiHost = apiBase.replace(/\/api\/v1\/?$/, '');
        const src = /^https?:\/\//i.test(user.photo_url) ? user.photo_url : (apiHost + user.photo_url);
        const img = document.createElement('img');
        img.src = src;
        img.alt = user.name || '';
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;';
        img.onerror = () => { navAvatar.removeChild(img); navAvatar.textContent = Fmt.initials(user.name); };
        navAvatar.appendChild(img);
      } else {
        navAvatar.textContent = Fmt.initials(user.name);
      }
    }
    if (navName)   navName.textContent   = user.name?.split(' ').slice(0, 2).join(' ') || 'You';
    if (navRole && role) {
      const roleLabel = {
        agency_admin:    'Platform admin',
        centre_director: 'Director',
        educator:        'Educator',
        guardian:        'Parent',
        auditor:         'Auditor',
      }[role] || role;
      navRole.textContent = roleLabel;
    }

    Dom.$('#navUser')?.addEventListener('click', () => {
      if (confirm('Sign out?')) Auth.logout();
    });

    buildNav(user);

    window.addEventListener('hashchange', renderScreen);
    renderScreen();
  }

  // Export
  window.KT.Shell = {
    registerScreen,
    renderScreen,
    navigate,
    Modal,
    Roles,
    emptyState,
    statusTagFor,
    buildNav,
    setBadge,
    startApp,
  };
})(window);
