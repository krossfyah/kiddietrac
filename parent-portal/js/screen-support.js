/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Parent Support & Feedback (2026-07-09).
   A dead-simple "report an issue / share feedback" screen for parents.
   Posts to POST /feedback (rating + category + comment) which notifies the
   centre director + agency admin. Mobile-first; styled by kt-mobile-app.css.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Api = KT.Api, Dom = KT.Dom, Shell = KT.Shell;

  var CATS = [
    { key: 'app_issue',  icon: '🐞', label: 'App issue / bug' },
    { key: 'suggestion', icon: '💡', label: 'Suggestion' },
    { key: 'billing',    icon: '💳', label: 'Billing question' },
    { key: 'general',    icon: '💬', label: 'General feedback' },
  ];

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') e.setAttribute('style', attrs[k]);
      else if (k === 'html') e.innerHTML = attrs[k];
      else e.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  var card = 'background:#fff;border-radius:16px;box-shadow:0 1px 6px rgba(15,23,42,.06);padding:16px;margin-bottom:14px;';

  function renderSupport(main) {
    if (Dom && Dom.clear) Dom.clear(main); else main.innerHTML = '';
    var wrap = el('div', { style: 'padding:2px 2px 8px;' });
    main.appendChild(wrap);

    wrap.appendChild(el('div', { style: 'font-size:18px;font-weight:800;color:#0f172a;margin:2px 2px 4px;' }, ['Support & Feedback']));
    wrap.appendChild(el('div', { style: 'font-size:13px;color:#64748b;margin:0 2px 14px;line-height:1.45;' },
      ['Report a problem with the app or share how we can do better. Your centre team is notified right away.']));

    var state = { category: 'app_issue', rating: 0 };

    // ── Category chips ──
    var cardEl = el('div', { style: card });
    cardEl.appendChild(el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.4px;color:#475569;margin-bottom:9px;text-transform:uppercase;' }, ['What is this about?']));
    var chips = el('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;' });
    CATS.forEach(function (c) {
      var chip = el('button', {
        type: 'button',
        'data-key': c.key,
        style: chipStyle(c.key === state.category),
      }, [c.icon + '  ' + c.label]);
      chip.addEventListener('click', function () {
        state.category = c.key;
        [].forEach.call(chips.children, function (b) { b.setAttribute('style', chipStyle(b.getAttribute('data-key') === state.category)); });
      });
      chips.appendChild(chip);
    });
    cardEl.appendChild(chips);
    wrap.appendChild(cardEl);

    // ── Rating ──
    var rateCard = el('div', { style: card });
    rateCard.appendChild(el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.4px;color:#475569;margin-bottom:9px;text-transform:uppercase;' }, ['How is your experience?']));
    var stars = el('div', { style: 'display:flex;gap:6px;' });
    for (var i = 1; i <= 5; i++) (function (n) {
      var s = el('button', { type: 'button', 'data-n': String(n), style: starStyle(false) }, ['★']);
      s.addEventListener('click', function () {
        state.rating = n;
        [].forEach.call(stars.children, function (b) { b.setAttribute('style', starStyle(Number(b.getAttribute('data-n')) <= n)); });
      });
      stars.appendChild(s);
    })(i);
    rateCard.appendChild(stars);
    wrap.appendChild(rateCard);

    // ── Message ──
    var msgCard = el('div', { style: card });
    msgCard.appendChild(el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.4px;color:#475569;margin-bottom:9px;text-transform:uppercase;' }, ['Tell us more']));
    var ta = el('textarea', {
      placeholder: 'Describe the issue, what you were doing, and what happened…',
      style: 'width:100%;box-sizing:border-box;min-height:120px;border:1.5px solid #e3eaf1;border-radius:12px;padding:12px 14px;font-size:16px;font-family:inherit;resize:vertical;',
    });
    msgCard.appendChild(ta);
    wrap.appendChild(msgCard);

    // ── Submit ──
    var status = el('div', { style: 'font-size:13px;margin:2px 2px 10px;min-height:18px;' });
    wrap.appendChild(status);
    var submit = el('button', {
      type: 'button',
      style: 'display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;cursor:pointer;padding:10px 22px;border-radius:10px;font-size:14px;font-weight:700;color:#fff;background:linear-gradient(135deg,#0FA3B1,#1F6FB2 60%,#2456A6);box-shadow:0 6px 16px -8px rgba(31,111,178,.5);',
    }, ['Send to my centre']);
    wrap.appendChild(submit);

    submit.addEventListener('click', function () {
      status.textContent = '';
      if (!state.rating) { status.style.color = '#b45309'; status.textContent = 'Please tap a star rating first.'; return; }
      if (!ta.value.trim()) { status.style.color = '#b45309'; status.textContent = 'Please describe the issue or feedback.'; ta.focus(); return; }
      submit.disabled = true; submit.textContent = 'Sending…';
      Api.post('/feedback', { rating: state.rating, category: state.category, comment: ta.value.trim() })
        .then(function () {
          Dom.clear(main);
          var ok = el('div', { style: 'text-align:center;padding:44px 24px;' }, [
            el('div', { style: 'font-size:52px;line-height:1;margin-bottom:14px;' }, ['✅']),
            el('div', { style: 'font-size:19px;font-weight:800;color:#0f172a;margin-bottom:6px;' }, ['Thank you!']),
            el('div', { style: 'font-size:14px;color:#64748b;line-height:1.5;max-width:320px;margin:0 auto 22px;' }, ['Your feedback has been sent to your centre team. They’ll follow up if needed.']),
          ]);
          var back = el('button', { type: 'button', style: 'border:1.5px solid #cbd5e1;background:#fff;color:#1F6FB2;font-weight:700;border-radius:12px;padding:12px 22px;cursor:pointer;font-size:14px;' }, ['Back to home']);
          back.addEventListener('click', function () { location.hash = '#home'; });
          ok.appendChild(back);
          main.appendChild(ok);
        })
        .catch(function (e) {
          submit.disabled = false; submit.textContent = 'Send to my centre';
          status.style.color = '#b91c1c'; status.textContent = 'Could not send: ' + (e && e.message ? e.message : 'please try again.');
        });
    });

    // ── Past submissions ──
    var past = el('div', { style: 'margin-top:22px;' });
    wrap.appendChild(past);
    Api.get('/feedback/mine').then(function (r) {
      var rows = (r && (r.data || r.feedback)) || [];
      if (!rows.length) return;
      past.appendChild(el('div', { style: 'font-size:12px;font-weight:800;letter-spacing:.4px;color:#94a3b8;margin:0 2px 8px;text-transform:uppercase;' }, ['Your past messages']));
      rows.slice(0, 8).forEach(function (f) {
        var row = el('div', { style: 'background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(15,23,42,.05);padding:12px 14px;margin-bottom:8px;' });
        row.appendChild(el('div', { style: 'display:flex;justify-content:space-between;gap:10px;margin-bottom:3px;' }, [
          el('div', { style: 'font-weight:700;font-size:13px;color:#334155;text-transform:capitalize;' }, [String(f.category || 'general').replace(/_/g, ' ')]),
          el('div', { style: 'font-size:12px;color:#f59e0b;' }, ['★'.repeat(f.rating || 0)]),
        ]));
        if (f.comment) row.appendChild(el('div', { style: 'font-size:13px;color:#64748b;line-height:1.4;' }, [f.comment]));
        past.appendChild(row);
      });
    }).catch(function () {});
  }

  function chipStyle(active) {
    return 'display:inline-flex;align-items:center;padding:9px 13px;border-radius:11px;font-size:13px;font-weight:600;cursor:pointer;'
      + (active
        ? 'background:rgba(31,111,178,.12);border:1.5px solid #1F6FB2;color:#1F6FB2;'
        : 'background:#fff;border:1.5px solid #e3eaf1;color:#475569;');
  }
  function starStyle(on) {
    return 'background:none;border:none;cursor:pointer;font-size:34px;line-height:1;padding:0 2px;color:' + (on ? '#f59e0b' : '#dbe3ec') + ';';
  }

  Shell.registerScreen('guardian:support', renderSupport);
  // Educators need a way to report a problem too — it was parent-only.
  Shell.registerScreen('educator:support', renderSupport);
  window.KT.renderSupport = renderSupport;
})(window);
