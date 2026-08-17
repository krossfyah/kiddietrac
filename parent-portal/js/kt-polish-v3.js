/* v22p65 — animated KPI counters + auto-tooltips + smooth interactions */
(function (window) {
  'use strict';
  if (window.KT_POLISH_V3_LOADED) return;
  window.KT_POLISH_V3_LOADED = true;
  const KT = window.KT = window.KT || {};

  // ============================ Animated KPI counters ============================
  function parseValue(text) {
    const t = String(text || '').trim();
    if (!t) return null;
    // Skip if not numeric-ish: "—" or "N/A"
    if (/^[—–-]$/.test(t) || /^N\/A$/i.test(t)) return null;
    // Pull number from $1,234.56 / 89% / 1,250 / +5
    const m = t.match(/^([\$\+\-]?)([\d,]+(?:\.\d+)?)([%]?)$/);
    if (!m) return null;
    return {
      prefix: m[1] || '',
      number: parseFloat(m[2].replace(/,/g, '')),
      suffix: m[3] || '',
      hasComma: m[2].includes(','),
    };
  }

  function animateCounter(el, target, duration = 1000) {
    const parsed = parseValue(el.textContent);
    if (!parsed) return;
    if (target.number === parsed.number) return;
    const startVal = 0;
    const endVal = target.number;
    const startTime = performance.now();
    el.closest('.kt-kpi') && el.closest('.kt-kpi').setAttribute('data-kt-animating', '1');
    const tick = (now) => {
      const elapsed = now - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      const current = startVal + (endVal - startVal) * eased;
      let display;
      if (target.number % 1 !== 0) display = current.toFixed(2);
      else display = Math.round(current);
      if (target.hasComma) display = Number(display).toLocaleString();
      el.textContent = target.prefix + display + target.suffix;
      if (progress < 1) requestAnimationFrame(tick);
      else {
        el.closest('.kt-kpi') && el.closest('.kt-kpi').removeAttribute('data-kt-animating');
      }
    };
    requestAnimationFrame(tick);
  }

  function sweepCounters() {
    document.querySelectorAll('[data-kt-pretty] .kt-kpi-value').forEach(el => {
      if (el.dataset.ktAnimated) return;
      const target = parseValue(el.textContent);
      if (!target) return;
      el.dataset.ktAnimated = '1';
      animateCounter(el, target);
    });
  }
  // Run on every screen render
  window.addEventListener('hashchange', () => {
    setTimeout(() => {
      // Reset markers so re-rendered KPIs animate again
      document.querySelectorAll('[data-kt-animated]').forEach(el => delete el.dataset.ktAnimated);
      sweepCounters();
    }, 600);
  });
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(sweepCounters) : setInterval(sweepCounters, 4000);
  setTimeout(sweepCounters, 900);

  // ============================ Auto-tooltips from title attribute ============================
  function syncTooltips() {
    document.querySelectorAll('[data-kt-pretty] [title]:not([data-kt-tooltip])').forEach(el => {
      const t = el.getAttribute('title');
      if (!t) return;
      el.setAttribute('data-kt-tooltip', t);
      el.removeAttribute('title'); // suppress native browser tooltip
    });
  }
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(syncTooltips) : setInterval(syncTooltips, 4000);
  setTimeout(syncTooltips, 1100);

  // ============================ Smooth scroll to top on hashchange ============================
  window.addEventListener('hashchange', () => {
    setTimeout(() => {
      const main = document.querySelector('main') || document.scrollingElement;
      if (main && main.scrollTo) main.scrollTo({ top: 0, behavior: 'smooth' });
      else window.scrollTo({ top: 0, behavior: 'smooth' });
    }, 100);
  });

  // ============================ Better empty-state polish ============================
  function polishEmpties() {
    document.querySelectorAll('[data-kt-pretty] tbody').forEach(tb => {
      if (tb.dataset.ktEmptyV3) return;
      if (tb.children.length === 1) {
        const td = tb.children[0].querySelector('td');
        if (td && td.colSpan && td.colSpan > 1 && /no\s+|none|empty|haven't|hasn't/i.test(td.textContent || '')) {
          tb.dataset.ktEmptyV3 = '1';
          const original = td.textContent.trim();
          // Pick an icon based on the text hint
          let icon = '📭';
          if (/photo/i.test(original)) icon = '📷';
          else if (/video/i.test(original)) icon = '🎬';
          else if (/message|chat/i.test(original)) icon = '💬';
          else if (/payment|invoice|bill/i.test(original)) icon = '💰';
          else if (/family|families|child/i.test(original)) icon = '👨‍👩‍👧';
          else if (/staff|user/i.test(original)) icon = '👥';
          else if (/observ/i.test(original)) icon = '🔭';
          else if (/notification/i.test(original)) icon = '🔔';
          else if (/document|form/i.test(original)) icon = '📄';
          else if (/calendar|event|schedule/i.test(original)) icon = '📅';
          else if (/tour/i.test(original)) icon = '🚪';
          else if (/cert|check|background/i.test(original)) icon = '🛡';
          td.innerHTML = `<div class="kt-empty-icon">${icon}</div>`
            + `<div class="kt-empty-title">${original}</div>`
            + `<div class="kt-empty-desc">When data is added, it'll appear here. Try changing the filter or check back later.</div>`;
          td.style.padding = '40px 20px';
        }
      }
    });
  }
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(polishEmpties) : setInterval(polishEmpties, 4000);
  setTimeout(polishEmpties, 1000);

  // ============================ Form field group helper ============================
  // For any <label> immediately followed by an input/select/textarea, wrap them
  // in a .kt-field for consistent spacing (no DOM-mutation if already wrapped).
  function polishFields() {
    document.querySelectorAll('[data-kt-pretty] label').forEach(lbl => {
      if (lbl.dataset.ktField) return;
      if (lbl.parentElement.classList && lbl.parentElement.classList.contains('kt-field')) {
        lbl.dataset.ktField = '1';
        return;
      }
      // Identify if next sibling is an input — if so, mark required if asterisk present
      const next = lbl.nextElementSibling;
      if (next && /^(INPUT|SELECT|TEXTAREA)$/.test(next.tagName)) {
        if (/\*\s*$/.test(lbl.textContent || '')) lbl.setAttribute('data-required', '1');
      }
      lbl.dataset.ktField = '1';
    });
  }
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(polishFields) : setInterval(polishFields, 4000);
  setTimeout(polishFields, 1200);

  // Expose helpers
  KT.animateCounter = animateCounter;
})(window);
