/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — Parent Feedback, for the educator it is about.

   Parents leave this from a link in their daily summary email. What reaches this
   screen is only what has been RELEASED: praise is released the moment it arrives,
   anything critical waits for a director to read it first and decide how to pass it
   on. An educator finding unfiltered criticism of themselves in a portal at 7pm is
   not a feedback loop, it is an ambush. (Anthony's call, 2026-08-26)

   Directors and agency admins see everything, released or not, on #feedback.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) return;
  var Shell = KT.Shell, Api = KT.Api, Dom = KT.Dom;

  function el(tag, style, text) {
    var e = document.createElement(tag);
    if (style) e.style.cssText = style;
    if (text != null) e.textContent = text;
    return e;
  }

  function stars(n) {
    n = parseInt(n, 10) || 0;
    if (!n) return '';
    return '★★★★★'.slice(0, n) + '☆☆☆☆☆'.slice(0, 5 - n);
  }

  /* A date, not a timestamp: for_date is the DAY being commented on and must not be
     shifted by anyone's timezone. created_at is a real instant, so it goes through the
     agency formatter. */
  function dayLabel(v) {
    if (!v) return '';
    try { if (KT.dayLabel) return KT.dayLabel(String(v).slice(0, 10)); } catch (e) {}
    return String(v).slice(0, 10);
  }

  function card(f) {
    var unread = !f.educator_read_at;
    var box = el('div', 'background:#fff;border:1px solid ' + (unread ? '#BFDBFE' : '#EEF0F3')
      + ';border-left:4px solid ' + (unread ? '#2563EB' : '#E2E8F0')
      + ';border-radius:12px;padding:14px 16px;margin-bottom:10px;');

    var head = el('div', 'display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;');
    var who = el('div', 'font-weight:800;font-size:14px;color:#0F172A;',
      (f.child_name || 'A child') + (f.for_date ? ' · ' + dayLabel(f.for_date) : ''));
    head.appendChild(who);
    if (f.rating) {
      head.appendChild(el('span', 'font-size:14px;color:#F59E0B;letter-spacing:1px;', stars(f.rating)));
    }
    box.appendChild(head);

    if (f.comment) {
      box.appendChild(el('div', 'font-size:14px;line-height:1.6;color:#334155;', f.comment));
    }
    if (f.tomorrow_note) {
      var note = el('div', 'margin-top:10px;background:#FFF7ED;border:1px solid #FED7AA;border-radius:9px;padding:9px 11px;');
      note.appendChild(el('div', 'font-size:10.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:#9A3412;margin-bottom:2px;', 'For tomorrow'));
      note.appendChild(el('div', 'font-size:13.5px;line-height:1.55;color:#7C2D12;', f.tomorrow_note));
      box.appendChild(note);
    }
    if (unread) {
      box.appendChild(el('div', 'margin-top:9px;font-size:11px;font-weight:800;color:#2563EB;', 'NEW'));
    }
    return box;
  }

  async function render(main) {
    Dom.clear(main);
    var wrap = el('div', 'padding:24px;max-width:820px;margin:0 auto;');
    main.appendChild(wrap);

    wrap.appendChild(el('h1', 'font-size:24px;margin:0 0 4px;color:#0F172A;', '💬 Parent feedback'));
    wrap.appendChild(el('div', 'color:#64748B;font-size:14px;margin-bottom:18px;',
      'What families have said about their child’s day.'));

    var body = el('div', '');
    wrap.appendChild(body);
    body.appendChild(el('div', 'color:#94A3B8;padding:20px;', 'Loading…'));

    var d;
    try { d = await Api.get('/feedback/mine-educator'); }
    catch (e) {
      Dom.clear(body);
      body.appendChild(el('div', 'color:#B91C1C;padding:16px;', 'Could not load feedback: ' + (e.message || 'error')));
      return;
    }

    Dom.clear(body);
    var rows = d.feedback || [];
    if (!rows.length) {
      body.appendChild(el('div',
        'background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:12px;padding:28px;text-align:center;color:#64748B;font-size:14px;',
        'No feedback yet. When a family leaves a note about their child’s day, it appears here.'));
      return;
    }

    if (d.unread) {
      body.appendChild(el('div',
        'background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:13.5px;color:#1E40AF;font-weight:700;',
        d.unread + ' new since you last looked'));
    }
    rows.forEach(function (f) { body.appendChild(card(f)); });

    /* Mark read only AFTER they have been rendered — marking on request would clear the
       badge for feedback the educator never actually saw. */
    if (d.unread) {
      var ids = rows.filter(function (f) { return !f.educator_read_at; }).map(function (f) { return f.id; });
      if (ids.length) {
        setTimeout(function () {
          Api.post('/feedback/mine-educator/read', { ids: ids })
            .then(function () { if (KT.Badges && KT.Badges.refresh) KT.Badges.refresh(); })
            .catch(function () {});
        }, 1200);
      }
    }
  }

  Shell.registerScreen('educator:parent-feedback', render);
  Shell.registerScreen('home_visitor:parent-feedback', render);
})(window);
