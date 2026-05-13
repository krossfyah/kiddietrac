/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v6 — Contextual Tooltips
   Adds "?" icons to high-value UI elements with helpful explanations.
   Uses MutationObserver since the UI is rendered dynamically.
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  // ─── Define the 15 high-value tooltip spots ────────────────────
  // Each entry: { selector: CSS selector, text: tooltip text }
  // selectors target text or labels likely to appear in the UI

  const TOOLTIPS = [
    {
      match: text => /^enrolled$/i.test(text),
      tooltip: "Total children currently enrolled in your centre. This counts children with an active enrollment, regardless of whether they're at the centre right now.",
      role: 'centre_director',
    },
    {
      match: text => /here right now/i.test(text),
      tooltip: "Children who've been checked in today and haven't been checked out yet. Updates in real time as educators check children in.",
      role: 'centre_director',
    },
    {
      match: text => /staff on floor/i.test(text),
      tooltip: "Staff currently clocked in. If this is 0 and you have children present, rooms will show as in BREACH. Ask educators to clock in via the tablet.",
      role: 'centre_director',
    },
    {
      match: text => /^receivables$/i.test(text),
      tooltip: "Total outstanding invoice balance across all families. Updates when invoices are generated or payments recorded.",
      role: 'centre_director',
    },
    {
      match: text => /^breach$/i.test(text),
      tooltip: "This room has more children than allowed by the educator ratio. A CCEYA violation if it lasts more than 15 minutes. Add an educator or move a child.",
      role: 'centre_director',
    },
    {
      match: text => /^tight$/i.test(text),
      tooltip: "You're at the legal minimum ratio. One more child or one educator on break puts you in breach. Bring in backup support.",
      role: 'centre_director',
    },
    {
      match: text => /^compliant$/i.test(text),
      tooltip: "Educator-to-child ratio is within CCEYA standards. You have appropriate staffing for the children present.",
      role: 'centre_director',
    },
    {
      match: text => /cwelcc/i.test(text),
      tooltip: "Canada-Wide Early Learning and Child Care system. Ontario children under 6 typically qualify for this subsidy, which significantly reduces family fees.",
      role: 'centre_director',
    },
    {
      match: text => /at centre/i.test(text),
      tooltip: "Your child is currently checked in at the daycare. The educator marked their arrival.",
      role: 'guardian',
    },
    {
      match: text => /at home/i.test(text),
      tooltip: "Your child has been checked out for the day, or hasn't arrived yet.",
      role: 'guardian',
    },
    {
      match: text => /^your daily digest$/i.test(text),
      tooltip: "An AI-written summary of your child's day, generated from what the educators logged. Updates throughout the day and finalizes around 6 PM.",
      role: 'guardian',
    },
    {
      match: text => /recent observations/i.test(text),
      tooltip: "Notable learning moments educators recorded. These build up over the year as a portfolio of your child's growth.",
      role: 'guardian',
    },
    {
      match: text => /your portion/i.test(text),
      tooltip: "What you owe after the CWELCC subsidy is applied to the full tuition. Pay this amount to your centre directly (e-transfer, cheque, etc.).",
      role: 'guardian',
    },
    {
      match: text => /^check[ -]in$/i.test(text),
      tooltip: "Marks a child as present at the centre. Should be tapped as parents drop their child off. Record mood at arrival.",
      role: 'educator',
    },
    {
      match: text => /^observation$/i.test(text),
      tooltip: "Record a notable learning moment — a milestone, new skill, or specific interaction worth remembering. These appear in the child's portfolio.",
      role: 'educator',
    },
  ];

  let currentRole = null;
  let observerActive = false;
  let injectedElements = new WeakSet();

  function getUserRole() {
    if (currentRole) return currentRole;
    try {
      const user = JSON.parse(sessionStorage.getItem('kt_user') || '{}');
      currentRole = user.primary_role || 'guardian';
    } catch {
      currentRole = 'guardian';
    }
    return currentRole;
  }

  function findTooltipForText(text) {
    if (!text || text.length > 60) return null;
    const trimmed = text.trim();
    if (trimmed.length === 0) return null;
    const role = getUserRole();
    return TOOLTIPS.find(t =>
      t.match(trimmed) && (
        !t.role || t.role === role ||
        (role === 'agency_admin' && t.role === 'centre_director')
      )
    );
  }

  function injectTooltipIcon(element, tooltipText) {
    if (injectedElements.has(element)) return;
    injectedElements.add(element);

    const icon = document.createElement('span');
    icon.className = 'kt-tooltip-icon';
    icon.textContent = '?';
    icon.title = tooltipText; // Native tooltip as fallback
    icon.style.cssText = `
      display: inline-flex; align-items: center; justify-content: center;
      width: 16px; height: 16px; border-radius: 50%;
      background: var(--ink-200, #E5E7EB); color: var(--ink-600, #6B7280);
      font-size: 11px; font-weight: 700; margin-left: 6px;
      cursor: help; vertical-align: middle; flex-shrink: 0;
      transition: background 0.15s;
    `;
    icon.addEventListener('mouseenter', () => icon.style.background = 'var(--brand-blue, #1F6080)');
    icon.addEventListener('mouseleave', () => icon.style.background = '');

    // Custom tooltip on click for mobile
    icon.addEventListener('click', (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      showCustomTooltip(icon, tooltipText);
    });

    element.appendChild(icon);
  }

  function showCustomTooltip(anchor, text) {
    // Remove any existing
    document.querySelectorAll('.kt-custom-tooltip').forEach(el => el.remove());

    const bubble = document.createElement('div');
    bubble.className = 'kt-custom-tooltip';
    bubble.textContent = text;
    bubble.style.cssText = `
      position: absolute; z-index: 10000;
      background: #1F2937; color: white;
      padding: 10px 14px; border-radius: 8px;
      font-size: 13px; line-height: 1.5;
      max-width: 280px; box-shadow: 0 4px 16px rgba(0,0,0,0.2);
      pointer-events: auto;
    `;

    const rect = anchor.getBoundingClientRect();
    bubble.style.left = (rect.left + window.scrollX + rect.width / 2 - 140) + 'px';
    bubble.style.top = (rect.bottom + window.scrollY + 8) + 'px';

    document.body.appendChild(bubble);

    // Dismiss on click outside or after 8 seconds
    setTimeout(() => bubble.remove(), 8000);
    document.addEventListener('click', function onClick(e) {
      if (e.target !== anchor && !bubble.contains(e.target)) {
        bubble.remove();
        document.removeEventListener('click', onClick);
      }
    });
  }

  function scanForTooltipTargets() {
    // Find all elements that contain only text (likely labels/headers)
    const candidates = document.querySelectorAll('div, span, h1, h2, h3, h4, label, button, a, p');

    candidates.forEach(el => {
      if (injectedElements.has(el)) return;
      // Only inject on leaf-ish elements (don't add to containers with many children)
      const directText = Array.from(el.childNodes)
        .filter(n => n.nodeType === 3)
        .map(n => n.textContent.trim())
        .join(' ');

      // Use either direct text or short total content
      const text = directText || (el.children.length === 0 ? el.textContent : '');
      if (!text) return;

      const tooltip = findTooltipForText(text);
      if (tooltip) {
        injectTooltipIcon(el, tooltip.tooltip);
      }
    });
  }

  function startObserver() {
    if (observerActive) return;
    observerActive = true;

    // Initial scan
    setTimeout(scanForTooltipTargets, 500);

    // Re-scan on DOM mutations (throttled)
    let pending = false;
    const observer = new MutationObserver(() => {
      if (pending) return;
      pending = true;
      setTimeout(() => {
        scanForTooltipTargets();
        pending = false;
      }, 250);
    });

    observer.observe(document.body, { childList: true, subtree: true });
  }

  // ─── Initialize ────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', () => {
    // Wait for user to be loaded into sessionStorage
    const tryStart = () => {
      const user = sessionStorage.getItem('kt_user');
      if (user) {
        startObserver();
      } else {
        setTimeout(tryStart, 500);
      }
    };
    tryStart();
  });

  // Expose for debugging
  window.KT = window.KT || {};
  window.KT._tooltips = TOOLTIPS;
})(window);
