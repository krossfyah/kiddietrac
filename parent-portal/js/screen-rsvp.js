/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Engagement → Event RSVPs  (agency admin)

   Templates hold the questions, the invite email, an image and the address that hears
   about sign-ups. An event is made from a template and keeps its own copy of the
   questions, so either can be edited at any time without disturbing past answers.
   Invite people by pasting or uploading a CSV, or by picking families and staff; each
   gets a personal RSVP link and an unsubscribe link. Reports show who was sent, who
   signed up and who opted out, with times in the agency's timezone, and export to CSV.

   Round 2 (2026-10-06): built-in templates, so an event can start without making one; a
   Preview of exactly what recipients get (and Send goes through it); per-event sender
   accounts on their own tab; an Unbranded setting; and a calmer layout — event cards with
   one status-driven action, the house ⋮ for the rest, sectioned editors with a footer bar.

   Backend: RsvpController (/admin/rsvp/*). Public page: /rsvp.html.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  var Api;
  var state = { tab: 'events', eventId: null, reportEvent: null, view: 'all', templates: [], presets: [], senders: [], defaultFrom: {}, gmail: {}, isPlatform: false, agencies: [] };
  var TYPES = [['text', 'Short answer'], ['textarea', 'Paragraph'], ['email', 'Email'], ['tel', 'Phone'], ['select', 'Dropdown'], ['checkbox', 'Checkbox']];
  var VIEWS = [['all', 'Everyone'], ['sent', 'Sent'], ['registered', 'Signed up'], ['opted_out', 'Opted out'], ['no_response', 'No response'], ['not_sent', 'Not sent']];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(m, t) { if (KT.toast) { KT.toast(m, t || 'info'); } }
  function isEmail(v) { return /^[^@\s,;<>]+@[^@\s,;<>]+\.[^@\s,;<>]+$/.test(String(v || '').trim()); }
  function statusPill(s) {
    var c = { open: ['#DCFCE7', '#166534', 'Open'], draft: ['#F1F5F9', '#475569', 'Draft'], closed: ['#FEE2E2', '#991B1B', 'Closed'] }[s] || ['#F1F5F9', '#475569', s];
    return '<span class="rv-pill" style="background:' + c[0] + ';color:' + c[1] + '">' + esc(c[2]) + '</span>';
  }
  function sendPill(s, label) {
    var c = { sent: ['#DCFCE7', '#166534'], queued: ['#E0F2FE', '#075985'], pending: ['#F1F5F9', '#475569'], suppressed: ['#FEF3C7', '#92400E'],
      failed: ['#FEE2E2', '#991B1B'], skipped: ['#F1F5F9', '#64748B'], not_invited: ['#EDE9FE', '#5B21B6'] }[s] || ['#F1F5F9', '#475569'];
    return '<span class="rv-pill" style="background:' + c[0] + ';color:' + c[1] + '">' + esc(label || s) + '</span>';
  }

  async function download(path, filename) {
    var tok = sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token');
    var headers = { Authorization: 'Bearer ' + tok };
    var aid = sessionStorage.getItem('kt_active_agency_id'); if (aid) { headers['X-Active-Agency-Id'] = aid; }
    var base = (KT.API_BASE || 'https://api.kiddietrac.com/api/v1');
    var r = await fetch(base + path, { headers: headers });
    if (!r.ok) { var msg = 'Download failed (' + r.status + ')'; try { var j = await r.json(); if (j.message) { msg = j.message; } } catch (e) {} toast(msg, 'error'); return; }
    var blob = await r.blob(), a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { try { URL.revokeObjectURL(a.href); } catch (e) {} }, 30000);
  }
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); toast('Link copied', 'success'); }
    catch (e) { await KT.prompt({ title: 'Copy this link', description: 'Select the link and copy it.', value: text, okLabel: 'Done' }); }
  }

  /* LAYOUT (2026-10-06). Anthony: "make the event RSVP look better with the buttons laid out
     in a better way". One button system for the whole screen (.rv-btn: 30px, three weights —
     primary, secondary, ghost), tabs on the left with the one primary action on the right,
     events as cards with ONE status-driven action and everything else in the house ⋮
     (KT.openActionMenu), and editors grouped into sections with a footer bar of
     Cancel · Preview · Save. Colours come from kt-tokens.css. The buttons carry
     data-kt-iconized so the icon sweep leaves their labels alone. */
  var CSS = '<style id="rv-css">'
    + '.rv-root{--rv-b:var(--kt-border,#E2E8F0);--rv-t:var(--kt-text,#0F172A);--rv-m:var(--kt-text-muted,#475569);--rv-s:var(--kt-bg-elevated,#fff);--rv-p:var(--kt-brand,#1F6080);--rv-r:var(--kt-r-md,10px)}'
    /* the one button system */
    + '#appMain .rv-btn,.rv-ov .rv-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:30px;min-height:0;padding:0 12px !important;margin:0;border:1px solid var(--kt-border,#CBD5E1);border-radius:8px;background:var(--kt-bg-elevated,#fff);color:var(--kt-text,#0F172A);font-weight:700;font-size:12.5px !important;line-height:1;font-family:inherit;white-space:nowrap;cursor:pointer;box-shadow:none;text-decoration:none;transition:background .12s,border-color .12s}'
    + '#appMain .rv-btn:hover,.rv-ov .rv-btn:hover{background:#F1F5F9;border-color:#CBD5E1}'
    + '#appMain .rv-btn.pri,.rv-ov .rv-btn.pri{background:var(--kt-brand,#1F6080);border-color:var(--kt-brand,#1F6080);color:#fff}'
    + '#appMain .rv-btn.pri:hover,.rv-ov .rv-btn.pri:hover{background:var(--kt-brand-dark,#174C66);border-color:var(--kt-brand-dark,#174C66)}'
    + '#appMain .rv-btn.ghost,.rv-ov .rv-btn.ghost{background:transparent;border-color:transparent;color:var(--kt-text-muted,#475569)}'
    + '#appMain .rv-btn.ghost:hover,.rv-ov .rv-btn.ghost:hover{background:#F1F5F9}'
    + '#appMain .rv-btn:disabled,.rv-ov .rv-btn:disabled{opacity:.5;cursor:default}'
    + '#appMain .rv-root .kt-ka-kebab{height:30px;width:32px}'
    /* header: tabs left, one primary right */
    + '.rv-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 14px}.rv-top .rv-tabs{margin:0;flex:1;min-width:0}'
    + '.rv-tabs{display:flex;gap:4px;flex-wrap:wrap;margin:0 0 14px;background:#EEF2F6;border-radius:10px;padding:3px;width:max-content;max-width:100%}'
    + '.rv-tabs button{border:0;background:transparent;border-radius:8px;padding:0 14px;height:28px;font-weight:700;font-size:12.5px;cursor:pointer;color:var(--kt-text-muted,#475569);font-family:inherit}'
    + '.rv-tabs button.on{background:var(--kt-bg-elevated,#fff);color:var(--kt-text,#0F172A);box-shadow:var(--kt-shadow-sm,0 1px 3px rgba(15,23,42,.08))}'
    + '.rv-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}.rv-bar select,.rv-bar input{padding:4px 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;height:30px}'
    + '.rv-pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11.5px;font-weight:700;white-space:nowrap;vertical-align:middle}'
    + '.rv-h{font-size:15px;margin:4px 0 6px}'
    /* event cards */
    + '.rv-evcards{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}'
    + '.rv-evc{background:var(--kt-bg-elevated,#fff);border:1px solid var(--kt-border,#E2E8F0);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;box-shadow:var(--kt-shadow-sm,none);min-width:0}'
    + '.rv-evc-img{display:block;width:100%;aspect-ratio:1200/400;object-fit:cover;background:#E2E8F0}'
    + '.rv-evc-body{padding:12px 14px 4px;display:grid;gap:6px;min-width:0}'
    + '.rv-evc-top{display:flex;gap:8px;align-items:flex-start;justify-content:space-between;min-width:0}'
    + '.rv-evc-title{font-weight:800;font-size:15px;color:var(--kt-text,#0F172A);text-decoration:none;line-height:1.3;min-width:0;overflow-wrap:anywhere}.rv-evc-title:hover{text-decoration:underline}'
    + '.rv-evc-meta{font-size:12.5px;color:var(--kt-text-muted,#475569);display:grid;gap:2px;min-width:0}.rv-evc-meta div{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.rv-evc-counts{display:flex;gap:6px;flex-wrap:wrap;margin-top:2px}.rv-evc-counts span{background:#F1F5F9;border-radius:8px;padding:3px 8px;font-size:11.5px;color:var(--kt-text-muted,#475569);font-weight:700}.rv-evc-counts b{color:var(--kt-text,#0F172A);font-size:13px;margin-right:3px}'
    + '.rv-evc-foot{display:flex;gap:6px;align-items:center;padding:10px 14px 12px;margin-top:auto;flex-wrap:wrap}.rv-evc-foot .sp{flex:1}'
    + '.rv-empty{background:var(--kt-bg-elevated,#fff);border:1px dashed #CBD5E1;border-radius:14px;padding:22px;text-align:center;color:var(--kt-text-muted,#475569);font-size:13.5px}'
    /* event page */
    + '.rv-head{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:14px 16px;display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap}'
    + '.rv-head img{width:200px;max-width:100%;border-radius:10px;border:1px solid #E2E8F0}.rv-head h3{margin:0 0 4px;font-size:18px}.rv-sub{font-size:13px;color:#475569;white-space:pre-line;margin:2px 0}'
    + '.rv-link{display:flex;gap:6px;align-items:center;margin-top:8px;flex-wrap:wrap;min-width:0}.rv-link code{background:#F1F5F9;border-radius:6px;padding:5px 8px;font-size:12px;word-break:break-all;min-width:0}'
    + '.rv-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin:12px 0}'
    + '.rv-stat{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:8px 12px}.rv-stat b{display:block;font-size:18px;color:#0F172A}.rv-stat span{font-size:11.5px;color:#64748B;font-weight:700}'
    + '.rv-secs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.rv-sec{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:12px 14px;min-width:0}.rv-sec.full{grid-column:1/-1}'
    + '.rv-sec>header{display:flex;align-items:center;gap:6px;margin:0 0 8px;flex-wrap:wrap}.rv-sec>header h4{margin:0;font-size:14px;flex:1;min-width:120px}'
    + '.rv-dl{display:grid;grid-template-columns:auto minmax(0,1fr);gap:4px 12px;font-size:13px;margin:0}.rv-dl dt{color:#64748B;font-weight:700}.rv-dl dd{margin:0;color:#0F172A;overflow-wrap:anywhere;white-space:pre-line}'
    + '.rv-qchips{display:flex;flex-wrap:wrap;gap:6px}.rv-qchips span{background:#F1F5F9;border-radius:8px;padding:3px 9px;font-size:12px;color:#334155}.rv-qchips span i{color:#B91C1C;font-style:normal}'
    + '.rv-mail{display:flex;gap:12px;align-items:flex-start;min-width:0}.rv-mail img{width:120px;border-radius:8px;border:1px solid #E2E8F0;flex:0 0 auto}.rv-mail .tx{min-width:0;font-size:13px;color:#334155}.rv-mail .tx b{display:block;color:#0F172A;margin-bottom:3px}.rv-mail .tx p{margin:0;color:#64748B;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;white-space:pre-line}'
    + '.rv-sec table{width:100%}.rv-sec .kt-card{box-shadow:none;border:0;padding:0;margin:0;background:transparent}'
    /* the footer bar — sticky on wide screens; on a phone it sits at the end, above the bottom nav */
    + '.rv-foot{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;background:var(--kt-bg-elevated,#fff);border:1px solid var(--kt-border,#E2E8F0);border-radius:12px;padding:10px 12px;margin-top:14px;box-shadow:0 -6px 18px -12px rgba(15,23,42,.25)}'
    + '.rv-foot .hint{margin-right:auto;font-size:12.5px;color:var(--kt-text-muted,#475569)}'
    /* position:sticky cannot work here: <body> is overflow:hidden, which makes it the
       nearest scroll container, so the bar would stick to the body rather than the screen.
       pinFooter() fixes it to the bottom of the content column instead (wide screens). */
    + '.rv-foot.pinned{position:fixed;bottom:12px;z-index:50;margin:0}.rv-root.has-pin{padding-bottom:84px !important}'
    /* dialogs */
    + '.rv-ov{position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:1000000;padding:16px}'
    + '.rv-dlg{background:#fff;border-radius:16px;width:100%;max-width:720px;max-height:92vh;overflow:auto;padding:18px 20px 0;box-shadow:0 24px 60px rgba(0,0,0,.25);display:flex;flex-direction:column}'
    + '.rv-dlg h3{margin:0 0 12px;font-size:17px}.rv-f{display:grid;grid-template-columns:1fr 1fr;gap:10px}.rv-f .full{grid-column:1/-1}'
    + '.rv-f label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:3px}'
    + '.rv-f input:not([type=checkbox]),.rv-f select,.rv-f textarea,.rv-q input:not([type=checkbox]),.rv-q select{width:100%;box-sizing:border-box;padding:5px 9px;min-height:32px;border:1px solid #CBD5E1;border-radius:8px;font-size:13.5px;font-family:inherit}'
    + '.rv-f textarea{min-height:90px}'
    + '.rv-fs{grid-column:1/-1;border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px 14px;margin:0;display:grid;grid-template-columns:1fr 1fr;gap:10px}.rv-fs legend{font-weight:800;font-size:13px;padding:0 6px;color:#0F172A}'
    + '.rv-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;position:sticky;bottom:0;background:#fff;border-top:1px solid #E2E8F0;margin:14px -20px 0;padding:10px 20px;z-index:2}'
    + '.rv-hint{font-size:12px;color:#64748B;margin-top:3px;line-height:1.45}'
    + '.rv-remchips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 4px}.rv-remchips button[disabled]{opacity:.45;cursor:not-allowed}'
    + '.rv-bulk{background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:6px 10px;position:sticky;top:0;z-index:2}.rv-bulk b{font-size:13px;color:#1E3A8A;margin-right:auto}'
    + '#appMain .rv-secs input[data-pick],#appMain .rv-secs input[data-selall]{width:16px;height:16px;cursor:pointer;margin:0}'
    + '.rv-q{border:1px solid #E2E8F0;border-radius:10px;padding:8px;margin-bottom:6px;display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto auto;gap:6px;align-items:center;background:#FBFDFF}'
    + '.rv-q .more{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:6px}.rv-q .req{display:flex;gap:4px;align-items:center;font-size:12px;font-weight:700;white-space:nowrap}'
    + '.rv-q .mv{display:flex;gap:2px}.rv-q .mv button{width:28px;height:28px;border:1px solid #CBD5E1;background:#fff;border-radius:7px;cursor:pointer;font-size:12px;padding:0}'
    + '.rv-q.locked{background:#F8FAFC}'
    + '.rv-chips{display:flex;gap:6px;flex-wrap:wrap}.rv-chips button{border:1px solid #CBD5E1;background:#fff;border-radius:16px;padding:0 12px;height:28px;font-size:12.5px;font-weight:700;cursor:pointer;color:#334155}.rv-chips button.on{background:#0F172A;color:#fff;border-color:#0F172A}'
    + '.rv-pick{max-height:300px;overflow:auto;border:1px solid #E2E8F0;border-radius:10px}.rv-pick label{display:flex;gap:8px;align-items:center;padding:6px 10px;border-bottom:1px solid #F1F5F9;font-size:13px;cursor:pointer}.rv-pick label small{color:#64748B}'
    /* built-ins, preview, senders */
    + '.rv-presets{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}'
    + '.rv-preset{background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}.rv-preset img{width:100%;aspect-ratio:1200/520;object-fit:cover;display:block}'
    + '.rv-preset .in{padding:10px 12px;display:flex;flex-direction:column;gap:2px;flex:1}.rv-preset .acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:auto;padding-top:8px}'
    + '.rv-warn{background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:10px;padding:8px 12px;font-size:12.5px;margin-bottom:10px;display:grid;gap:2px}'
    + '.rv-meta{display:grid;gap:3px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 12px;margin-bottom:8px;font-size:12.5px}'
    + '.rv-meta div{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;min-width:0}.rv-meta span{width:64px;flex:0 0 64px;color:#64748B;font-weight:700}.rv-meta b{color:#0F172A;word-break:break-word;min-width:0}.rv-meta em{color:#64748B;font-style:normal}'
    + '.rv-frame iframe{width:100%;height:56vh;border:1px solid #E2E8F0;border-radius:10px;background:#fff;color-scheme:light;display:block}'
    + '.rv-help{background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:10px 12px;font-size:12px;color:#475569;line-height:1.6}.rv-help code{background:#E2E8F0;border-radius:4px;padding:0 4px}'
    /* phone: one column, actions wrap, footer as a 2-column grid, no sideways scroll */
    + '@media (max-width:640px){'
    +   '.rv-root{padding:12px 10px !important}'
    /* compact controls on a phone too (the UI-tight rule), over the portal's 44px phone sizing */
    +   'html body #appMain .rv-root .rv-btn,html body .rv-ov .rv-btn,html body #appMain .rv-root .kt-ka-kebab{min-height:0 !important;height:32px !important;font-size:13px !important;padding-top:0 !important;padding-bottom:0 !important}'
    +   'html body #appMain .rv-root .rv-tabs button,html body .rv-ov .rv-tabs button{min-height:0 !important;height:30px !important;font-size:12.5px !important;padding:0 8px !important}'
    +   '.rv-top .rv-tabs{display:grid;grid-template-columns:1fr 1fr;width:100%;box-sizing:border-box}'
    +   '.rv-f,.rv-fs{grid-template-columns:minmax(0,1fr)}.rv-q{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.rv-q .more{grid-template-columns:minmax(0,1fr)}.rv-head img{width:100%}'
    +   '.rv-top{align-items:stretch}.rv-top .rv-tabs{width:100%}.rv-tabs button{flex:1 1 auto;padding:0 10px}.rv-top>.rv-btn{width:100%}'
    +   '.rv-evcards{grid-template-columns:minmax(0,1fr)}.rv-secs{grid-template-columns:minmax(0,1fr)}'
    +   '.rv-foot{display:grid;grid-template-columns:1fr 1fr;justify-content:stretch}.rv-foot .hint{grid-column:1/-1;margin:0}.rv-foot .rv-btn{width:100%}.rv-foot .rv-btn.pri{grid-column:1/-1;order:9}'
    +   '.rv-actions{display:grid;grid-template-columns:1fr 1fr;margin:14px -20px 0}.rv-actions .rv-btn{width:100%}.rv-actions .rv-btn.pri,.rv-actions .rv-btn:only-of-type{grid-column:1/-1;order:9}'
    +   '.rv-dlg{padding:16px 16px 0}.rv-actions{margin:14px -16px 0;padding:10px 16px}.rv-ov{padding:8px}'
    +   '.rv-mail{flex-direction:column}.rv-mail img{width:100%}'
    + '}'
    + '</style>';

  /* A dialog of our own (wide forms); a drag that ends on the backdrop does not close it.
     Footer, right-aligned: Cancel (ghost) · any extra such as Preview (secondary) · Save
     (primary). It sticks to the bottom of the dialog while the form scrolls. */
  function dialog(title, bodyHtml, onSave, saveLabel, opts) {
    opts = opts || {};
    var ov = document.createElement('div');
    ov.className = 'rv-ov';
    ov.innerHTML = '<div class="rv-dlg" role="dialog" aria-modal="true" style="' + (opts.width ? 'max-width:' + opts.width + 'px' : '') + '"><h3>' + esc(title) + '</h3>' + bodyHtml
      + '<div class="rv-actions"><button type="button" class="rv-btn ghost" data-kt-iconized="1" data-x>Cancel</button>'
      + (opts.extra || []).map(function (x, i) { return '<button type="button" class="rv-btn" data-kt-iconized="1" data-extra="' + i + '">' + esc(x.label) + '</button>'; }).join('')
      + (onSave ? '<button type="button" class="rv-btn pri" data-kt-iconized="1" data-ok>' + esc(saveLabel || 'Save') + '</button>' : '') + '</div></div>';
    document.body.appendChild(ov);
    var down = null;
    ov.addEventListener('mousedown', function (e) { down = e.target; });
    ov.addEventListener('click', function (e) { if (e.target === ov && down === ov) { ov.remove(); } });
    ov.querySelector('[data-x]').onclick = function () { ov.remove(); };
    ov.querySelectorAll('[data-extra]').forEach(function (b) {
      b.onclick = function () { try { opts.extra[+b.getAttribute('data-extra')].onClick(ov.querySelector('.rv-dlg')); } catch (e) { toast((e && e.message) || 'Could not open', 'error'); } };
    });
    var ok = ov.querySelector('[data-ok]');
    if (ok) {
      ok.onclick = async function () {
        ok.disabled = true;
        try { var keep = await onSave(ov.querySelector('.rv-dlg')); if (keep !== false) { ov.remove(); } } catch (e) { toast((e && e.message) || 'Could not save', 'error'); }
        ok.disabled = false;
      };
    }
    return ov;
  }
  function field(k, label, v, o) {
    o = o || {};
    var cls = o.full ? ' class="full"' : '';
    var hint = o.hint ? '<div class="rv-hint">' + o.hint + '</div>' : '';
    if (o.select) {
      return '<div' + cls + '><label>' + esc(label) + '</label><select data-k="' + k + '">' + o.select.map(function (x) {
        return '<option value="' + esc(x[0]) + '"' + (String(x[0]) === String(v == null ? '' : v) ? ' selected' : '') + '>' + esc(x[1]) + '</option>'; }).join('') + '</select>' + hint + '</div>';
    }
    if (o.area) { return '<div' + cls + '><label>' + esc(label) + '</label><textarea data-k="' + k + '" maxlength="' + (o.max || 4000) + '"' + (o.rows ? ' rows="' + o.rows + '"' : '') + '>' + esc(v || '') + '</textarea>' + hint + '</div>'; }
    return '<div' + cls + '><label>' + esc(label) + '</label><input data-k="' + k + '" type="' + (o.type || 'text') + '" value="' + esc(v == null ? '' : v) + '"'
      + (o.ph ? ' placeholder="' + esc(o.ph) + '"' : '') + (o.min ? ' min="' + o.min + '"' : '') + '>' + hint + '</div>';
  }
  function vals(root) {
    var o = {};
    root.querySelectorAll('[data-k]').forEach(function (el) { o[el.getAttribute('data-k')] = el.type === 'checkbox' ? el.checked : el.value.trim(); });
    return o;
  }
  var PLACEHOLDER_HINT = 'You can use {{first_name}}, {{name}}, {{event}}, {{date}}, {{location}} and {{agency}}. The RSVP button, the event details and an unsubscribe link are added for you.';

  /* ── image picker (upload → preview) ── */
  function imageBlock(url) {
    return '<div class="full"><label>Image (shown at the top of the email and the RSVP page)</label><div data-img style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
      + '<img data-imgprev src="' + esc(url || '') + '" alt="" style="max-width:220px;max-height:110px;border-radius:8px;border:1px solid #E2E8F0;' + (url ? '' : 'display:none') + '">'
      + '<input type="file" accept="image/png,image/jpeg,image/gif,image/webp" data-imgfile style="display:none">'
      + '<button type="button" class="rv-btn" data-kt-iconized="1" data-imgup>' + (url ? 'Replace image' : 'Upload image') + '</button>'
      + '<button type="button" class="rv-btn" data-kt-iconized="1" data-imgclear' + (url ? '' : ' style="display:none"') + '>Remove image</button>'
      + '<input type="hidden" data-k="image_url" value="' + esc(url || '') + '"></div><div class="rv-hint">JPG, PNG, GIF or WebP, up to 5 MB. A wide image (about 1200×500) looks best.</div></div>';
  }
  function wireImage(root) {
    var up = root.querySelector('[data-imgup]'); if (!up) { return; }
    var file = root.querySelector('[data-imgfile]'), prev = root.querySelector('[data-imgprev]'), hid = root.querySelector('[data-k="image_url"]'), clr = root.querySelector('[data-imgclear]');
    up.onclick = function () { file.click(); };
    clr.onclick = function () { hid.value = ''; hid.setAttribute('data-dirty', '1'); prev.style.display = 'none'; clr.style.display = 'none'; up.textContent = 'Upload image'; };
    file.onchange = async function () {
      var f = file.files && file.files[0]; if (!f) { return; }
      if (f.size > 5 * 1024 * 1024) { toast('That image is larger than 5 MB', 'error'); return; }
      var fd = new FormData(); fd.append('image', f);
      up.disabled = true; up.textContent = 'Uploading…';
      try {
        var r = await Api.postForm('/admin/rsvp/image', fd);
        hid.value = r.url; hid.setAttribute('data-dirty', '1'); prev.src = r.url; prev.style.display = ''; clr.style.display = ''; up.textContent = 'Replace image';
      } catch (e) { toast(e.message || 'Upload failed', 'error'); up.textContent = hid.value ? 'Replace image' : 'Upload image'; }
      up.disabled = false; file.value = '';
    };
  }

  /* ── questions builder ── */
  function qRow(q) {
    var locked = q.key === 'email';
    return '<div class="rv-q' + (locked ? ' locked' : '') + '" data-q data-key="' + esc(q.key || '') + '">'
      + '<input data-ql placeholder="Question" value="' + esc(q.label || '') + '" maxlength="160">'
      + '<select data-qt' + (locked ? ' disabled' : '') + '>' + TYPES.map(function (t) { return '<option value="' + t[0] + '"' + (t[0] === (q.type || 'text') ? ' selected' : '') + '>' + t[1] + '</option>'; }).join('') + '</select>'
      + '<label class="req" title="' + (locked ? 'Email is always required' : 'Required') + '"><input type="checkbox" data-qr' + (q.required || locked ? ' checked' : '') + (locked ? ' disabled' : '') + '> Required</label>'
      + '<div class="mv"><button type="button" data-up title="Move up">↑</button><button type="button" data-down title="Move down">↓</button>'
      + (locked ? '' : '<button type="button" data-del title="Remove question">✕</button>') + '</div>'
      + '<div class="more"><input data-qo placeholder="Choices, separated by commas" value="' + esc((q.options || []).join(', ')) + '" style="' + (q.type === 'select' ? '' : 'display:none') + '">'
      + '<input data-qp placeholder="Placeholder (optional)" value="' + esc(q.placeholder || '') + '" maxlength="160">'
      + '<input data-qd placeholder="Pre-filled answer (optional)" value="' + esc(q.default || '') + '" maxlength="160">'
      + '<input data-qh placeholder="Help text under the question (optional)" value="' + esc(q.help || '') + '" maxlength="300"></div></div>';
  }
  function questionsBlock(fields) {
    var list = (fields && fields.length) ? fields : [{ key: 'full_name', label: 'Full name', type: 'text', required: true }, { key: 'email', label: 'Email', type: 'email', required: true }];
    if (!list.some(function (f) { return f.key === 'email'; })) { list.splice(1, 0, { key: 'email', label: 'Email', type: 'email', required: true }); }
    return '<div class="full"><label>Questions on the RSVP form</label><div data-qs>' + list.map(qRow).join('') + '</div>'
      + '<button type="button" class="rv-btn" data-kt-iconized="1" data-qadd>＋ Add question</button><div class="rv-hint">Email is always asked and always required. Changing questions later never alters answers already given.</div></div>';
  }
  function wireQuestions(root) {
    var box = root.querySelector('[data-qs]'); if (!box) { return; }
    root.querySelector('[data-qadd]').onclick = function () {
      var d = document.createElement('div'); d.innerHTML = qRow({ label: '', type: 'text' }); box.appendChild(d.firstChild);
      box.lastChild.querySelector('[data-ql]').focus();
    };
    box.addEventListener('click', function (e) {
      var row = e.target.closest('[data-q]'); if (!row) { return; }
      if (e.target.closest('[data-del]')) { row.remove(); }
      else if (e.target.closest('[data-up]') && row.previousElementSibling) { box.insertBefore(row, row.previousElementSibling); }
      else if (e.target.closest('[data-down]') && row.nextElementSibling) { box.insertBefore(row.nextElementSibling, row); }
    });
    box.addEventListener('change', function (e) {
      if (!e.target.matches('[data-qt]')) { return; }
      e.target.closest('[data-q]').querySelector('[data-qo]').style.display = e.target.value === 'select' ? '' : 'none';
    });
  }
  function readQuestions(root) {
    var out = [];
    root.querySelectorAll('[data-q]').forEach(function (row) {
      var label = row.querySelector('[data-ql]').value.trim(); if (!label) { return; }
      var key = row.getAttribute('data-key') || '';
      out.push({ key: key, label: label, type: key === 'email' ? 'email' : row.querySelector('[data-qt]').value, required: key === 'email' ? true : row.querySelector('[data-qr]').checked,
        options: row.querySelector('[data-qo]').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean),
        placeholder: row.querySelector('[data-qp]').value.trim(), default: row.querySelector('[data-qd]').value.trim(), help: row.querySelector('[data-qh]').value.trim() });
    });
    var bad = out.filter(function (q) { return q.type === 'select' && !q.options.length; })[0];
    if (bad) { throw new Error('Add the choices for “' + bad.label + '”.'); }
    return out;
  }

  /* ── render ── */
  async function render(main) {
    Api = KT.Api;
    gmailReturn();
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = CSS + '<div class="rv-root" data-kt-self-live style="padding:14px 24px;max-width:1300px;">'
      + '<div class="kt-page-hero"><h2>🎟️ Event RSVPs</h2><p>Invite people to an event, collect their RSVPs, and see who was sent, who signed up and who opted out.</p></div>'
      /* Tabs on the left, the screen's ONE primary action on the right. */
      + '<div class="rv-top"><div class="rv-tabs" role="tablist"><button type="button" data-tab="events">Events</button><button type="button" data-tab="templates">Templates</button>'
      + '<button type="button" data-tab="senders">Sender accounts</button><button type="button" data-tab="reports">Reports</button></div>'
      + '<button type="button" class="rv-btn pri" data-kt-iconized="1" data-newevent>＋ New event</button></div>'
      + '<div id="rv-body">Loading…</div></div>';
    main.querySelector('.rv-top .rv-tabs').addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]'); if (!b) { return; }
      state.tab = b.getAttribute('data-tab'); state.eventId = null; paint(main);
    });
    main.querySelector('[data-newevent]').onclick = function () { eventDialog(main, null); };
    await paint(main);
  }

  async function paint(main) {
    if (!main.isConnected) { return; }
    main.querySelectorAll('.rv-top .rv-tabs button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === state.tab); b.setAttribute('aria-selected', b.getAttribute('data-tab') === state.tab ? 'true' : 'false'); });
    var body = main.querySelector('#rv-body');
    try {
      var t = await Api.get('/admin/rsvp/templates');
      state.templates = t.templates || []; state.isPlatform = !!t.is_platform; state.agencies = t.agencies || [];
      state.presets = t.presets || []; state.senders = t.senders || []; state.defaultFrom = t.default_from || {}; state.gmail = t.gmail || {};
    } catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }
    var rr = main.querySelector('.rv-root'); if (rr && !state.eventId) { rr.classList.remove('has-pin'); }
    if (state.tab === 'templates') { return paintTemplates(main, body); }
    if (state.tab === 'senders') { return paintSenders(main, body); }
    if (state.tab === 'reports') { return paintReports(main, body); }
    if (state.eventId) { return paintEvent(main, body, state.eventId); }
    return paintEvents(main, body);
  }

  /* ── Events: one card per event, ONE action that follows its status ── */
  function primaryFor(e) {
    var pend = (e.stats || {}).pending || 0;
    if (e.status === 'draft') { return ['send', 'Preview & send']; }
    if (e.status === 'open') { return pend ? ['send', 'Send ' + pend + ' invitation' + (pend === 1 ? '' : 's')] : ['invite', 'Invite more']; }
    return ['report', 'View report'];
  }
  function runPrimary(main, e, kind) {
    if (kind === 'send') { previewDialog({ event_id: e.id }, { send: true, ev: e, main: main, onSent: function () { paint(main); } }); }
    else if (kind === 'invite') { inviteDialog(main, e); }
    else if (kind === 'report') { state.tab = 'reports'; state.reportEvent = e.id; state.view = 'all'; state.eventId = null; paint(main); }
  }

  /* The secondary actions, for the house ⋮ (KT.openActionMenu). Each is a detached button
     whose click is what runs; labels are words, and "Delete" picks up the red styling. */
  function eventMenu(main, e, onPage) {
    var items = [];
    function add(label, run) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = label;
      b.addEventListener('click', function () { run(); });
      items.push(b);
    }
    if (!onPage) { add('📂 Open', function () { state.eventId = e.id; paint(main); }); }
    add('✏️ Edit', function () { eventDialog(main, e); });
    add('👁 Preview', function () { previewDialog({ event_id: e.id }); });
    add('🔗 Copy link', function () { copy(e.public_url); });
    add('📄 Duplicate', async function () {
      try { var r = await Api.post('/admin/rsvp/events/' + e.id + '/duplicate', {}); toast('Copied as a draft', 'success'); state.tab = 'events'; state.eventId = r.id; paint(main); }
      catch (err) { toast(err.message || 'Could not duplicate', 'error'); }
    });
    if (e.status === 'open') {
      add('⏸ Close event', async function () {
        var ok = await KT.confirm({ title: 'Close “' + e.title + '”?', description: 'The RSVP link stops taking sign-ups. Everything already received stays in the report, and you can reopen it later.', okLabel: 'Close event' });
        if (!ok) { return; }
        setStatus(main, e, 'closed');
      });
    } else {
      add(e.status === 'draft' ? '▶ Open for RSVPs' : '▶ Reopen', function () { setStatus(main, e, 'open'); });
    }
    add('💾 Save as my template', async function () {
      var nm = await KT.prompt({ title: 'Save as my template', description: 'Keeps this event’s questions, invitation email, image, branding and sign-up address as a template for future events.', value: e.title, placeholder: 'Template name', okLabel: 'Save template' });
      if (nm === null) { return; }
      nm = String(nm || '').trim(); if (!nm) { toast('Give the template a name', 'error'); return; }
      try { await Api.post('/admin/rsvp/events/' + e.id + '/save-template', { name: nm }); toast('Saved to your templates', 'success'); }
      catch (err) { toast(err.message || 'Could not save', 'error'); }
    });
    add('🗑 Delete', async function () {
      var ok = await KT.confirm({ title: 'Delete “' + e.title + '”?', description: 'Its RSVP link stops working and anything not yet sent is held back. The report is no longer available.', okLabel: 'Delete event', tone: 'danger' });
      if (!ok) { return; }
      try { await Api.delete('/admin/rsvp/events/' + e.id); toast('Event deleted', 'success'); state.eventId = null; paint(main); } catch (err) { toast(err.message || 'Could not delete', 'error'); }
    });
    return items;
  }
  async function setStatus(main, e, status) {
    try { await Api.post('/admin/rsvp/events/' + e.id + '/status', { status: status }); toast(status === 'open' ? 'Open — taking RSVPs' : 'Event closed', 'success'); paint(main); }
    catch (err) { toast(err.message || 'Could not update', 'error'); }
  }
  function openMenu(btn, items) {
    if (window.KT && KT.openActionMenu) { btn.classList.add('kt-ka-kebab'); KT.openActionMenu(btn, items); }
    else if (items[0]) { items[0].click(); }
  }

  async function paintEvents(main, body) {
    var d = await Api.get('/admin/rsvp/events');
    if (!main.isConnected) { return; }
    var rows = d.events || [];
    if (!rows.length) {
      body.innerHTML = '<div class="rv-empty">No events yet. Press <b>＋ New event</b> and start from a built-in template — or one of your own — to get a shareable RSVP link.</div>';
      body.onclick = null;
      return;
    }
    /* A CARD LIST, not a table, so kt-row-actions cannot collapse these actions: the ⋮ here
       is the documented card exception, and the menu it opens is the house one
       (KT.openActionMenu), never a hand-built popup. */
    body.innerHTML = '<div class="rv-evcards">' + rows.map(function (e) {
      var s = e.stats || {}, pa = primaryFor(e);
      return '<article class="rv-evc">'
        + (e.image_url ? '<img class="rv-evc-img" src="' + esc(e.image_url) + '" alt="" loading="lazy">' : '')
        + '<div class="rv-evc-body"><div class="rv-evc-top"><a href="#" class="rv-evc-title" data-open="' + e.id + '">' + esc(e.title) + '</a>' + statusPill(e.status) + '</div>'
        + '<div class="rv-evc-meta"><div>📅 ' + esc(e.when || 'No date set') + '</div>'
        + (e.location ? '<div>📍 ' + esc(String(e.location).split('\n')[0]) + '</div>' : '')
        + (e.branding === 'none' ? '<div>🏷️ Unbranded</div>' : '') + '</div>'
        + '<div class="rv-evc-counts"><span><b>' + (s.invited || 0) + '</b>invited</span><span><b>' + (s.registered || 0) + '</b>signed up</span><span><b>' + (s.opted_out || 0) + '</b>opted out</span></div></div>'
        + '<div class="rv-evc-foot"><button type="button" class="rv-btn pri" data-kt-iconized="1" data-pa="' + pa[0] + '" data-id="' + e.id + '">' + esc(pa[1]) + '</button>'
        + (e.status === 'open' ? '<button type="button" class="rv-btn ghost" data-kt-iconized="1" data-pa="report" data-id="' + e.id + '">View report</button>' : '')
        + '<span class="sp"></span><button type="button" class="kt-ka-kebab" data-kt-iconized="1" data-menu="' + e.id + '" title="More actions" aria-label="More actions for ' + esc(e.title) + '">⋮</button></div></article>';
    }).join('') + '</div>';
    body.onclick = function (ev) {
      var b = ev.target.closest('[data-open],[data-pa],[data-menu]'); if (!b) { return; }
      ev.preventDefault();
      var id = +(b.getAttribute('data-open') || b.getAttribute('data-id') || b.getAttribute('data-menu'));
      var e = rows.filter(function (x) { return x.id === id; })[0]; if (!e) { return; }
      if (b.hasAttribute('data-open')) { state.eventId = id; paint(main); }
      else if (b.hasAttribute('data-pa')) { runPrimary(main, e, b.getAttribute('data-pa')); }
      else { ev.stopPropagation(); openMenu(b, eventMenu(main, e, false)); }
    };
  }

  /* ── where an event's content starts: one of your templates, or a built-in ── */
  function startOptions() {
    var html = '';
    if (state.templates.length) {
      html += '<optgroup label="Your templates">' + state.templates.map(function (t) { return '<option value="t:' + t.id + '">' + esc(t.name) + '</option>'; }).join('') + '</optgroup>';
    }
    html += '<optgroup label="Built-in templates">' + state.presets.map(function (p) { return '<option value="p:' + esc(p.key) + '">' + esc(p.name) + '</option>'; }).join('') + '</optgroup>';
    return html;
  }
  function startSource(v) {
    v = String(v || '');
    if (v.indexOf('t:') === 0) { return state.templates.filter(function (t) { return 't:' + t.id === v; })[0] || null; }
    if (v.indexOf('p:') === 0) { return state.presets.filter(function (p) { return 'p:' + p.key === v; })[0] || null; }
    return null;
  }
  function senderOptions(sel) {
    var df = state.defaultFrom || {};
    return [['', 'Agency default email' + (df.address ? ' — ' + df.address : '')]].concat(state.senders.map(function (s) {
      var st = s.type === 'gmail_oauth'
        ? (s.google_status === 'connected' ? '' : s.google_status === 'needs_reconnect' ? ' (needs reconnecting)' : ' (Gmail not connected yet)')
        : ' (custom server — can’t send from this hosting)';
      return [s.id, s.label + (s.from_address ? ' — ' + s.from_address : '') + st];
    })).map(function (x) {
      return '<option value="' + esc(x[0]) + '"' + (String(x[0]) === String(sel == null ? '' : sel) ? ' selected' : '') + '>' + esc(x[1]) + '</option>';
    }).join('');
  }
  var BRANDING = [['agency', 'Agency branded — your logo, header and footer'], ['none', 'Unbranded — no KiddieTrac or agency name, logo, header or footer']];
  var BRANDING_HINT = 'Unbranded applies to the invitation, the confirmation, the sign-up and opt-out notices and the RSVP page: they show only this event’s image, title, text, details and questions, and a plain unsubscribe line.';
  function fs(legend, inner) { return '<fieldset class="rv-fs"><legend>' + esc(legend) + '</legend>' + inner + '</fieldset>'; }

  /* The values in an open event dialog, as the preview endpoint takes them. */
  function draftFrom(root, ev) {
    var v = vals(root), d = {};
    ['title', 'description', 'location', 'image_url', 'email_subject', 'email_body', 'confirmation_message', 'notify_email', 'starts_local', 'ends_local', 'branding', 'show_map', 'map_pin', 'attend_tips'].forEach(function (k) {
      if (v[k] != null) { d[k] = v[k] === '' ? null : v[k]; }
    });
    d.sender_id = v.sender_id ? +v.sender_id : null;
    if (ev) { d.event_id = ev.id; }
    else {
      var src = String(v.start || '');
      if (src.indexOf('t:') === 0) { d.template_id = +src.slice(2); } else if (src.indexOf('p:') === 0) { d.preset = src.slice(2); }
    }
    return d;
  }

  /* New / edit event, grouped: Details · Questions · Email & image · Sender & branding ·
     Invitees. Footer: Cancel · Preview · Save. */
  function eventDialog(main, ev, startWith) {
    var first = startWith || (state.templates.length ? 't:' + state.templates[0].id : 'p:' + ((state.presets[0] || {}).key || 'general'));
    var src = ev ? null : startSource(first) || {};
    var c = ev || src;
    var qs = (c.fields || []);
    var html = '<div class="rv-f">'
      + fs('Details', (ev ? '' : '<div class="full"><label>Start from</label><select data-k="start">' + startOptions() + '</select>'
          + '<div class="rv-hint" data-srcdesc>' + esc(src.description || '') + '</div></div>')
        + field('title', 'Event title', ev ? ev.title : '', { full: true, ph: 'e.g. ' + ((src && src.name) || 'Spring open house') })
        + field('starts_local', 'Starts', ev ? ev.starts_local : '', { type: 'datetime-local' })
        + field('ends_local', 'Ends', ev ? ev.ends_local : '', { type: 'datetime-local' })
        + field('location', 'Where', ev ? ev.location : '', { area: true, full: true, rows: 2, max: 250 })
        + field('description', 'About the event (shown on the RSVP page)', ev ? ev.description : '', { area: true, full: true })
        + field('capacity', 'Capacity (optional)', ev ? ev.capacity : '', { type: 'number', min: 1, ph: 'No limit' })
        + field('status', 'Status', ev ? ev.status : 'open', { select: [['open', 'Open — taking RSVPs'], ['draft', 'Draft — link not live yet'], ['closed', 'Closed — no more RSVPs']] }))
      + fs('Questions', '<div class="full"><div class="rv-qchips" data-qchips>' + qChips(qs) + '</div><div class="rv-hint">'
        + (ev ? 'Change them with <b>Edit questions</b> on the event page.' : 'From the template you start from. You can change them once the event is created.') + '</div></div>')
      + fs('Email & image', imageBlock(c.image_url)
        + field('email_subject', 'Subject line', c.email_subject, { full: true, ph: 'e.g. Town of Mono — Engaged Citizens, October 14',
          hint: 'Your own subject line, exactly as people will see it. {{event}} puts in the event title and {{first_name}} the person’s first name — use them or leave them out.' })
        + field('email_body', 'Message', c.email_body, { area: true, full: true, max: 8000, hint: PLACEHOLDER_HINT })
        + field('confirmation_message', 'Confirmation message (sent to each registrant)', c.confirmation_message, { area: true, full: true })
        + field('attend_tips', 'Tips for attendees (confirmation email)', ev ? ev.attend_tips : '', { area: true, full: true, rows: 6, max: 4000,
          hint: 'One tip per line. Start a line with a short heading and a colon to make it bold — e.g. “Speak up: your questions shape the evening.” A “Spread the word” line with the RSVP link is added under them.' })
        + field('show_map', 'Map in confirmation email', (ev && ev.show_map === false) || (ev && String(ev.show_map) === '0') ? '0' : '1', { select: [['1', 'Show a map of the location, with directions'], ['0', 'No map']] })
        + field('map_pin', 'Exact map pin (optional)', ev ? ev.map_pin : '', { ph: 'Paste a Google Maps link, or 44.0175, -80.0711',
          hint: 'Use this when the map puts the pin in the wrong place — rural addresses often are not mapped exactly. In Google Maps, find the venue, press Share and copy the link.' }))
      + fs('Sender & branding', '<div><label>Send from</label><select data-k="sender_id">' + senderOptions(ev ? ev.sender_id : '') + '</select>'
          + '<div class="rv-hint">Which mailbox the invitations and confirmations come from. Add accounts on the Sender accounts tab.</div></div>'
        + field('notify_email', 'Send sign-ups and opt-outs to', ev ? ev.notify_email : ((src && src.notify_email) || ''), { type: 'email', ph: 'events@yourorganisation.ca',
          hint: 'Gets every sign-up and opt-out, and is the Reply-To on invitations.' })
        + field('branding', 'Branding', c.branding || 'agency', { select: BRANDING, full: true, hint: BRANDING_HINT })
        + '<div class="full rv-warn" data-brandwarn style="display:none;margin:0"></div>')
      + fs('Invitees', '<div class="full rv-hint" style="margin:0">' + (ev
          ? ((ev.stats && ev.stats.invited) || 0) + ' on the list. Add more from the event page — paste, upload a CSV, or pick families and staff.'
          : 'Add invitees after you create the event — paste a list, upload a CSV, or pick families and staff.') + '</div>')
      + '</div>';
    var ov = dialog(ev ? 'Edit event' : 'New event', html, async function (root) {
      var v = vals(root);
      if (!v.title) { throw new Error('Give the event a title.'); }
      if (!isEmail(v.notify_email)) { throw new Error('Enter the address that should receive sign-ups.'); }
      v.capacity = v.capacity ? +v.capacity : null;
      v.sender_id = v.sender_id ? +v.sender_id : null;
      if (v.show_map != null) { v.show_map = v.show_map === '1'; }
      ['starts_local', 'ends_local'].forEach(function (k) { if (!v[k]) { v[k] = null; } });
      if (!ev) {
        var s = String(v.start || '');
        if (s.indexOf('t:') === 0) { v.template_id = +s.slice(2); } else { v.preset = s.slice(2); }
      }
      delete v.start;
      var r = await Api.post('/admin/rsvp/events' + (ev ? '/' + ev.id : ''), v);
      toast(ev ? 'Event saved' : 'Event created', 'success');
      state.tab = 'events'; state.eventId = ev ? ev.id : r.id; paint(main);
    }, ev ? 'Save event' : 'Create event', { width: 800, extra: [{ label: '👁 Preview', onClick: function (root) { previewDialog(draftFrom(root, ev)); } }] });
    // Switching what to start from refills the content — but never over something you changed.
    ['email_subject', 'email_body', 'confirmation_message', 'notify_email', 'branding'].forEach(function (k) {
      var el = ov.querySelector('[data-k="' + k + '"]'); if (el) { el.addEventListener('input', function () { el.setAttribute('data-dirty', '1'); }); el.addEventListener('change', function () { el.setAttribute('data-dirty', '1'); }); }
    });
    var sel = ov.querySelector('[data-k="start"]');
    if (sel) {
      sel.value = first;
      sel.onchange = function () {
        var t = startSource(sel.value); if (!t) { return; }
        ov.querySelector('[data-srcdesc]').textContent = t.description || '';
        ov.querySelector('[data-qchips]').innerHTML = qChips(t.fields || []);
        ['email_subject', 'email_body', 'confirmation_message'].forEach(function (k) {
          var el = ov.querySelector('[data-k="' + k + '"]'); if (el && !el.getAttribute('data-dirty')) { el.value = t[k] || ''; }
        });
        var br = ov.querySelector('[data-k="branding"]'); if (br && !br.getAttribute('data-dirty')) { br.value = t.branding || 'agency'; }
        var n = ov.querySelector('[data-k="notify_email"]'); if (n && !n.getAttribute('data-dirty') && t.notify_email) { n.value = t.notify_email; }
        var hid = ov.querySelector('[data-k="image_url"]'), prev = ov.querySelector('[data-imgprev]'), clr = ov.querySelector('[data-imgclear]'), up = ov.querySelector('[data-imgup]');
        if (hid && !hid.getAttribute('data-dirty')) {
          hid.value = t.image_url || ''; prev.src = t.image_url || ''; prev.style.display = t.image_url ? '' : 'none';
          clr.style.display = t.image_url ? '' : 'none'; up.textContent = t.image_url ? 'Replace image' : 'Upload image';
        }
        brandWarn();
      };
    }
    var ss = ov.querySelector('[data-k="sender_id"]'), bsel = ov.querySelector('[data-k="branding"]');
    // Unbranded mail still comes from the platform address unless a sender account is chosen.
    function brandWarn() {
      var w = ov.querySelector('[data-brandwarn]'), df = state.defaultFrom || {};
      var show = bsel.value === 'none' && !ss.value;
      w.style.display = show ? '' : 'none';
      w.textContent = show ? 'Unbranded, but with no sender account the email still comes from ' + (df.address || 'the platform address') + '. Choose a sender account to send from your own address.' : '';
    }
    ss.addEventListener('change', function () {
      var s = state.senders.filter(function (x) { return String(x.id) === ss.value; })[0];
      var n = ov.querySelector('[data-k="notify_email"]');
      if (s && s.reply_to && n && !n.value.trim()) { n.value = s.reply_to; }
      brandWarn();
    });
    bsel.addEventListener('change', brandWarn);
    brandWarn();
    wireImage(ov);
  }
  function qChips(fields) {
    return (fields || []).map(function (f) { return '<span>' + esc(f.label) + (f.required ? ' <i>*</i>' : '') + '</span>'; }).join('') || '<span>No questions</span>';
  }

  /* ═════════════ PREVIEW — exactly what recipients get, sending nothing ═════════════
     The invitation and confirmation are rendered by the server's own email code
     (POST /admin/rsvp/preview), and the RSVP page by the real rsvp.html in preview mode,
     so what is checked here is what goes out. In send mode it is the step before Send:
     a draft is opened and sent in one go, and an event with nobody waiting offers to add
     invitees instead. */
  async function previewDialog(params, opts) {
    opts = opts || {};
    var d;
    try { d = await Api.post('/admin/rsvp/preview', params); }
    catch (e) { toast(e.message || 'Could not build the preview', 'error'); return; }
    var tab = 'invite';
    var ov = document.createElement('div');
    ov.className = 'rv-ov';
    var n = d.pending || 0, isDraft = d.status === 'draft', closed = d.status === 'closed';
    var sendLabel = (isDraft ? 'Open event & send ' : 'Send ') + n + ' invitation' + (n === 1 ? '' : 's');
    var hint = closed ? 'This event is closed. Reopen it to send.'
      : (n ? n + ' ' + (n === 1 ? 'person is' : 'people are') + ' waiting for an invitation.' : 'Nobody is waiting for an invitation yet.');
    ov.innerHTML = '<div class="rv-dlg rv-pv" role="dialog" aria-modal="true" style="max-width:880px">'
      + '<h3>' + (opts.send ? 'Check before sending' : 'Preview') + ' — ' + esc(d.form.title || '') + (d.branding === 'none' ? ' <span class="rv-pill" style="background:#F1F5F9;color:#475569">Unbranded</span>' : '') + '</h3>'
      + '<div class="rv-tabs" role="tablist" data-pvtabs><button type="button" data-pv="invite" class="on">Invitation email</button><button type="button" data-pv="page">RSVP page</button><button type="button" data-pv="confirmation">Confirmation email</button></div>'
      + (d.warnings && d.warnings.length ? '<div class="rv-warn">' + d.warnings.map(function (w) { return '<div>⚠ ' + esc(w) + '</div>'; }).join('') + '</div>' : '')
      + '<div class="rv-meta" data-meta></div>'
      + '<div class="rv-frame" data-frame></div>'
      + '<div class="rv-hint" style="margin-top:6px">Shown for a sample recipient, “Alex Sample”. The buttons and links in a preview go nowhere.</div>'
      + '<div class="rv-actions">'
      + (opts.send ? '<span class="rv-hint" style="margin:0 auto 0 0;align-self:center">' + esc(hint) + '</span>' : '')
      + '<button type="button" class="rv-btn ghost" data-kt-iconized="1" data-x>' + (opts.send ? 'Cancel' : 'Close') + '</button>'
      + (opts.send && !closed && !n && opts.ev ? '<button type="button" class="rv-btn pri" data-kt-iconized="1" data-addinv>＋ Add invitees</button>' : '')
      + (opts.send && !closed && n ? '<button type="button" class="rv-btn pri" data-kt-iconized="1" data-send>' + esc(sendLabel) + '</button>' : '')
      + '</div></div>';
    document.body.appendChild(ov);
    var down = null;
    ov.addEventListener('mousedown', function (e) { down = e.target; });
    ov.addEventListener('click', function (e) { if (e.target === ov && down === ov) { ov.remove(); } });
    ov.querySelector('[data-x]').onclick = function () { ov.remove(); };
    function meta(m) {
      return '<div><span>From</span><b>' + esc(d.from.name ? d.from.name + ' <' + d.from.address + '>' : d.from.address) + '</b><em>' + esc(d.from.account) + '</em></div>'
        + '<div><span>Reply-To</span><b>' + esc(d.reply_to || '— not set —') + '</b></div>'
        + '<div><span>To</span><b>' + esc(d.to) + '</b></div>'
        + '<div><span>Subject</span><b>' + esc(d[m].subject) + '</b></div>';
    }
    function show() {
      ov.querySelectorAll('[data-pv]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-pv') === tab); });
      var mb = ov.querySelector('[data-meta]'), fr = ov.querySelector('[data-frame]');
      fr.innerHTML = '';
      var f = document.createElement('iframe');
      f.title = 'Preview';
      if (tab === 'page') {
        mb.innerHTML = '<div><span>Page</span><b>The RSVP form people see from the invitation or the shareable link</b></div>';
        f.src = '/rsvp.html?preview=1';
        f.onload = function () { try { f.contentWindow.postMessage({ type: 'kt-rsvp-preview', event: d.form }, location.origin); } catch (e) {} };
      } else {
        mb.innerHTML = meta(tab);
        // No scripts and no same-origin access: an email is shown, nothing in it runs.
        f.setAttribute('sandbox', '');
        f.srcdoc = d[tab].html;
      }
      fr.appendChild(f);
    }
    ov.querySelector('[data-pvtabs]').onclick = function (e) { var b = e.target.closest('[data-pv]'); if (!b) { return; } tab = b.getAttribute('data-pv'); show(); };
    show();
    var ab = ov.querySelector('[data-addinv]');
    if (ab) { ab.onclick = function () { ov.remove(); inviteDialog(opts.main, opts.ev); }; }
    var sb = ov.querySelector('[data-send]');
    if (sb) {
      sb.onclick = async function () {
        var ok = await KT.confirm({ title: sendLabel + '?', description: (isDraft ? 'This opens the event for RSVPs, then e' : 'E') + 'veryone on the list who has not been sent one yet gets their personal RSVP link, from ' + d.from.address + '. Anyone who has opted out is skipped.', okLabel: isDraft ? 'Open & send' : 'Send invitations' });
        if (!ok) { return; }
        sb.disabled = true;
        try {
          if (isDraft) { await Api.post('/admin/rsvp/events/' + params.event_id + '/status', { status: 'open' }); }
          var r = await Api.post('/admin/rsvp/events/' + params.event_id + '/send', {});
          toast(r.queued + ' invitation(s) on their way', 'success'); ov.remove(); if (opts.onSent) { opts.onSent(); }
        } catch (e) { sb.disabled = false; toast(e.message || 'Could not send', 'error'); }
      };
    }
  }

  /* The event page, in sections — Details · Questions · Email & image · Sender & branding ·
     Invitees — with a footer bar: Back (ghost) · Preview · the one primary action. */
  /* Keep the event page's footer bar in view on wide screens, aligned to the content column. */
  function pinFooter(main) {
    var f = main.querySelector('.rv-foot'), root = main.querySelector('.rv-root');
    if (!f || !root) { return; }
    function place() {
      if (!f.isConnected) { window.removeEventListener('resize', place); return; }
      if (window.innerWidth <= 768) { f.classList.remove('pinned'); root.classList.remove('has-pin'); f.style.left = f.style.width = ''; return; }
      var r = root.getBoundingClientRect(), cs = getComputedStyle(root);
      var pl = parseFloat(cs.paddingLeft) || 0, pr = parseFloat(cs.paddingRight) || 0;
      f.classList.add('pinned'); root.classList.add('has-pin');
      f.style.left = (r.left + pl) + 'px'; f.style.width = Math.max(0, r.width - pl - pr) + 'px';
    }
    place();
    window.addEventListener('resize', place);
  }

  /* ── Reminders (2026-10-06) ─────────────────────────────────────────────
     Anthony: "add a reminder feature in the event to be triggered by a schedule or
     manually to send out a reminder about the upcoming event". A schedule is "N before the
     event starts" (follows the event if its time changes) or a set date and time; the
     server sends what is due every five minutes. "Send a reminder now" goes through a
     preview and a confirm with the number of people it will reach. */
  var REM_OFFSETS = [[10080, '1 week before'], [4320, '3 days before'], [1440, '1 day before'], [720, '12 hours before'], [180, '3 hours before'], [120, '2 hours before'], [60, '1 hour before']];
  var REM_AUD = [['registered', 'Everyone who signed up'], ['no_response', 'Invited, but not replied yet'], ['both', 'Both — signed up and not replied']];
  function audLabel(a) { return ({ registered: 'Signed up', no_response: 'Not replied yet', both: 'Signed up + not replied' })[a] || a; }
  function remPill(s) {
    var c = { scheduled: ['#EFF6FF', '#1D4ED8', 'Scheduled'], sending: ['#FEF3C7', '#92400E', 'Sending'], sent: ['#ECFDF5', '#047857', 'Sent'],
      cancelled: ['#F1F5F9', '#64748B', 'Cancelled'], skipped: ['#FEF2F2', '#B91C1C', 'Not sent'] }[s] || ['#F1F5F9', '#475569', s];
    return '<span class="rv-pill" style="background:' + c[0] + ';color:' + c[1] + '">' + c[2] + '</span>';
  }

  async function loadReminders(main, host, ev) {
    var d;
    try { d = await Api.get('/admin/rsvp/events/' + ev.id + '/reminders'); }
    catch (e) { host.innerHTML = '<div class="rv-hint" style="color:#B91C1C">Could not load reminders: ' + esc(e.message || e) + '</div>'; return; }
    if (!host.isConnected) { return; }
    var r = d.reminders || [], reach = d.reach || {};
    var warn = '';
    if (!d.has_start) { warn += '<div class="rv-warn" style="margin:0 0 8px">⚠ This event has no date and time yet. Reminders “before the event” need one — set it with Edit, or schedule a reminder for a specific date.</div>'; }
    if (d.blocker) { warn += '<div class="rv-warn" style="margin:0 0 8px">⚠ ' + esc(d.blocker) + ' Reminders only go out while the event is open and before it starts.</div>'; }
    var chips = '<div class="rv-chips rv-remchips"><span class="rv-hint" style="margin:0 4px 0 0;align-self:center">Add:</span>'
      + [[10080, '1 week before'], [1440, '1 day before'], [120, '2 hours before']].map(function (o) { return '<button type="button" data-remadd="' + o[0] + '"' + (d.has_start ? '' : ' disabled') + '>＋ ' + o[1] + '</button>'; }).join('')
      + '<button type="button" data-remadd="custom">＋ Custom…</button></div>';
    var reachTxt = '<div class="rv-hint" style="margin:6px 0 8px">Right now a reminder would reach <b>' + (reach.registered || 0) + '</b> signed up'
      + ' and <b>' + (reach.no_response || 0) + '</b> invited who haven’t replied. People who opted out are never included.</div>';
    var list = r.length ? '<div class="kt-card" style="overflow:auto"><table data-kt-noexport data-kt-no-bulk data-kt-no-filter><thead><tr><th>When</th><th>To</th><th>Status</th><th>Result</th><th></th></tr></thead><tbody>'
      + r.map(function (x) {
        var when = x.manual ? 'Sent by hand' + (x.sent_label ? '<div class="rv-hint">' + esc(x.sent_label) + '</div>' : '')
          : esc(x.offset_label || 'Set time') + '<div class="rv-hint">' + esc(x.status === 'sent' ? (x.sent_label || x.due_label) : (x.due_label || 'needs the event’s start time')) + '</div>';
        var res = x.status === 'sent' ? (x.recipient_count ? x.recipient_count + ' queued · ' + x.sent_count + ' sent' + (x.failed_count ? ' · ' + x.failed_count + ' not delivered' : '') : '—')
          : (x.status === 'scheduled' ? '—' : '');
        return '<tr><td>' + when + '</td><td>' + esc(audLabel(x.audience)) + (x.subject ? '<div class="rv-hint">“' + esc(x.subject) + '”</div>' : '') + '</td>'
          + '<td>' + remPill(x.status) + (x.status_note ? '<div class="rv-hint">' + esc(x.status_note) + '</div>' : '') + '</td><td>' + res + '</td>'
          + '<td style="text-align:right"><button type="button" class="kt-ka-kebab" data-kt-iconized="1" data-remmenu="' + x.id + '" title="Reminder actions" aria-label="Reminder actions">⋮</button></td></tr>';
      }).join('') + '</tbody></table></div>'
      : '<div class="rv-hint" style="margin:4px 0 0">No reminders yet. Add one above — “1 day before” is a good start — or send one now.</div>';
    host.innerHTML = warn + chips + reachTxt + list;

    host.onclick = async function (e) {
      var b = e.target.closest('button'); if (!b || !host.contains(b)) { return; }
      e.stopPropagation();
      var refresh = function () { loadReminders(main, host, ev); };
      if (b.hasAttribute('data-remadd')) {
        var v = b.getAttribute('data-remadd');
        if (v === 'custom') { reminderDialog(ev, null, d, refresh); return; }
        try { await Api.post('/admin/rsvp/events/' + ev.id + '/reminders', { audience: 'registered', offset_minutes: +v }); toast('Reminder scheduled', 'success'); refresh(); }
        catch (err) { toast(err.message || 'Could not schedule', 'error'); }
        return;
      }
      if (b.hasAttribute('data-remmenu')) {
        var x = r.filter(function (y) { return y.id === +b.getAttribute('data-remmenu'); })[0]; if (!x) { return; }
        var items = [];
        var add = function (label, run) { var it = document.createElement('button'); it.type = 'button'; it.textContent = label; it.addEventListener('click', function () { run(); }); items.push(it); };
        if (x.status === 'scheduled') {
          add('✏️ Edit', function () { reminderDialog(ev, x, d, refresh); });
          add('🔔 Send it now', function () { sendReminderNow(ev, x, refresh); });
        }
        add('👁 Preview', function () { reminderPreview(ev, { audience: x.audience, subject: x.subject, message: x.message }); });
        add('✉️ Send a test', function () { reminderTest(ev, { audience: x.audience, subject: x.subject, message: x.message }); });
        if (x.status === 'scheduled') {
          add('✕ Cancel reminder', async function () {
            var ok = await KT.confirm({ title: 'Cancel this reminder?', description: 'It will not be sent. You can add a new one any time.', okLabel: 'Cancel reminder', cancelLabel: 'Keep it' });
            if (!ok) { return; }
            try { await Api.post('/admin/rsvp/events/' + ev.id + '/reminders/' + x.id + '/cancel', {}); toast('Reminder cancelled', 'success'); refresh(); }
            catch (err) { toast(err.message || 'Could not cancel', 'error'); }
          });
        }
        openMenu(b, items);
      }
    };
  }

  /* Create or edit a scheduled reminder. */
  function reminderDialog(ev, x, d, done) {
    var defs = (d && d.defaults) || {};
    var mode = x && x.offset_minutes == null && x.send_local ? 'time' : 'offset';
    var offs = REM_OFFSETS.slice();
    if (x && x.offset_minutes && !offs.some(function (o) { return o[0] === x.offset_minutes; })) { offs.push([x.offset_minutes, x.offset_label]); }
    var html = '<div class="rv-f">'
      + field('rmode', 'When', mode, { select: [['offset', 'Before the event starts'], ['time', 'At a specific date and time']], full: true })
      + '<div class="full" data-offwrap><label>How long before</label><select data-k="offset_minutes">' + offs.map(function (o) { return '<option value="' + o[0] + '"' + (x && x.offset_minutes === o[0] ? ' selected' : (!x && o[0] === 1440 ? ' selected' : '')) + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>'
      + (d && !d.has_start ? '<div class="rv-hint" style="color:#B45309">This event has no start time yet — set one with Edit first, or pick a specific date and time.</div>' : '') + '</div>'
      + '<div class="full" data-timewrap><label>Send at (' + esc((d && d.tz) || 'agency time') + ')</label><input type="datetime-local" data-k="send_local" value="' + esc(x ? x.send_local : '') + '"></div>'
      + field('audience', 'Send to', x ? x.audience : 'registered', { select: REM_AUD, full: true })
      + field('subject', 'Subject line (optional)', x ? x.subject : '', { full: true, ph: defs.subject || 'Reminder: {{event}} is coming up' })
      + field('message', 'Message (optional)', x ? x.message : '', { area: true, full: true, rows: 5, max: 4000,
          hint: 'Leave blank for a friendly default — people who signed up get “see you there” with their RSVP details; people who haven’t replied get an “RSVP now” button. The event details, the map and your tips are added for you. You can use {{first_name}}, {{event}}, {{date}} and {{location}}.' })
      + '</div>';
    var ov = dialog(x ? 'Edit reminder' : 'Schedule a reminder', html, async function (root) {
      var v = vals(root), body = { audience: v.audience, subject: v.subject || null, message: v.message || null };
      if (v.rmode === 'time') { if (!v.send_local) { throw new Error('Pick the date and time.'); } body.send_local = v.send_local; }
      else { body.offset_minutes = +v.offset_minutes; }
      await Api.post('/admin/rsvp/events/' + ev.id + '/reminders' + (x ? '/' + x.id : ''), body);
      toast(x ? 'Reminder updated' : 'Reminder scheduled', 'success');
      if (done) { done(); }
    }, x ? 'Save reminder' : 'Schedule reminder', { width: 620, extra: [
      { label: '👁 Preview', onClick: function (root) { var v = vals(root); reminderPreview(ev, { audience: v.audience, subject: v.subject, message: v.message }); } },
      { label: '✉️ Test', onClick: function (root) { var v = vals(root); reminderTest(ev, { audience: v.audience, subject: v.subject, message: v.message }); } }] });
    var dlg = ov.querySelector('.rv-dlg');
    var sync = function () { var t = dlg.querySelector('[data-k="rmode"]').value === 'time'; dlg.querySelector('[data-offwrap]').style.display = t ? 'none' : ''; dlg.querySelector('[data-timewrap]').style.display = t ? '' : 'none'; };
    dlg.querySelector('[data-k="rmode"]').addEventListener('change', sync);
    sync();
  }

  /* Send now: write it (or take a scheduled one), see it, confirm the count, send. */
  function sendReminderNow(ev, x, done) {
    var go = async function (body) {
      var pv;
      try { pv = await Api.post('/admin/rsvp/events/' + ev.id + '/reminders-preview', body); }
      catch (err) { toast(err.message || 'Could not prepare the reminder', 'error'); return false; }
      var n = pv.recipients || 0;
      if (!n) { toast('Nobody to remind for that choice — check who it goes to.', 'info'); return false; }
      var ok = await KT.confirm({ title: 'Send this reminder to ' + n + (n === 1 ? ' person' : ' people') + ' now?',
        description: 'Each of them gets their own copy' + (pv.from && pv.from.address ? ' from ' + (pv.from.name ? pv.from.name + ' ' : '') + '<' + pv.from.address + '>' : '') + '. People who opted out are never included.', okLabel: 'Send ' + n });
      if (!ok) { return false; }
      try {
        var r = await Api.post('/admin/rsvp/events/' + ev.id + '/reminders-send', x ? { reminder_id: x.id } : body);
        toast(r.queued ? r.queued + ' reminder' + (r.queued === 1 ? '' : 's') + ' on the way — they go out within a few minutes' : (r.note || 'Nothing was sent'), r.queued ? 'success' : 'info');
        if (done) { done(); }
      } catch (err) { toast(err.message || 'Could not send', 'error'); return false; }
      return true;
    };
    if (x) { go({ audience: x.audience, subject: x.subject, message: x.message }); return; }
    var html = '<div class="rv-f">'
      + field('audience', 'Send to', 'registered', { select: REM_AUD, full: true })
      + field('subject', 'Subject line (optional)', '', { full: true, ph: 'Reminder: {{event}} is coming up' })
      + field('message', 'Message (optional)', '', { area: true, full: true, rows: 5, max: 4000,
          hint: 'Leave blank for a friendly default. The event details, the map and your tips are added for you.' })
      + '</div>';
    dialog('Send a reminder now', html, async function (root) {
      var v = vals(root);
      var sent = await go({ audience: v.audience, subject: v.subject || null, message: v.message || null });
      return sent ? true : false;
    }, 'Continue', { width: 600, extra: [
      { label: '👁 Preview', onClick: function (root) { var v = vals(root); reminderPreview(ev, { audience: v.audience, subject: v.subject, message: v.message }); } },
      { label: '✉️ Test', onClick: function (root) { var v = vals(root); reminderTest(ev, { audience: v.audience, subject: v.subject, message: v.message }); } }] });
  }

  async function reminderPreview(ev, body) {
    var d;
    try { d = await Api.post('/admin/rsvp/events/' + ev.id + '/reminders-preview', { audience: body.audience || 'registered', subject: body.subject || null, message: body.message || null }); }
    catch (e) { toast(e.message || 'Could not build the preview', 'error'); return; }
    var tabs = [['registered', 'Signed up'], ['no_response', 'Not replied yet']].filter(function (t) { return d[t[0]]; });
    var tab = tabs[0][0];
    var ov = document.createElement('div');
    ov.className = 'rv-ov';
    ov.innerHTML = '<div class="rv-dlg rv-pv" role="dialog" aria-modal="true" style="max-width:880px"><h3>Reminder preview</h3>'
      + (tabs.length > 1 ? '<div class="rv-tabs" data-pvtabs>' + tabs.map(function (t) { return '<button type="button" data-pv="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>' : '')
      + '<div class="rv-meta" data-meta></div><div class="rv-frame" data-frame></div>'
      + '<div class="rv-hint" style="margin-top:6px">Shown for a sample person, “Alex Sample”. It would reach ' + (d.recipients || 0) + ' ' + ((d.recipients === 1) ? 'person' : 'people') + ' right now. Nothing is sent from here.</div>'
      + '<div class="rv-actions"><button type="button" class="rv-btn ghost" data-kt-iconized="1" data-x>Close</button></div></div>';
    document.body.appendChild(ov);
    var down = null;
    ov.addEventListener('mousedown', function (e) { down = e.target; });
    ov.addEventListener('click', function (e) { if (e.target === ov && down === ov) { ov.remove(); } });
    ov.querySelector('[data-x]').onclick = function () { ov.remove(); };
    function show() {
      ov.querySelectorAll('[data-pv]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-pv') === tab); });
      ov.querySelector('[data-meta]').innerHTML = '<div><span>From</span><b>' + esc(d.from && d.from.address ? (d.from.name ? d.from.name + ' <' + d.from.address + '>' : d.from.address) : 'Agency email') + '</b></div>'
        + '<div><span>Subject</span><b>' + esc(d[tab].subject) + '</b></div>';
      var fr = ov.querySelector('[data-frame]'); fr.innerHTML = '';
      var f = document.createElement('iframe'); f.title = 'Preview'; f.setAttribute('sandbox', ''); f.srcdoc = d[tab].html; fr.appendChild(f);
    }
    var t = ov.querySelector('[data-pvtabs]');
    if (t) { t.onclick = function (e) { var b = e.target.closest('[data-pv]'); if (!b) { return; } tab = b.getAttribute('data-pv'); show(); }; }
    show();
  }

  async function reminderTest(ev, body) {
    var to = await KT.prompt({ title: 'Send a test reminder', description: 'Sends one copy, marked [Test], to the address you enter — as someone who signed up' + (body.audience === 'no_response' ? ' (or, for “not replied yet”, with the RSVP button)' : '') + '. Its buttons open the shareable page, so nobody is signed up by it.', placeholder: 'you@example.com', type: 'email', okLabel: 'Send test' });
    if (to === null) { return; }
    to = String(to || '').trim();
    if (!isEmail(to)) { toast('Enter an email address', 'error'); return; }
    try {
      var r = await Api.post('/admin/rsvp/events/' + ev.id + '/reminders-test', { to: to, audience: body.audience || 'registered', subject: body.subject || null, message: body.message || null });
      toast(r.sent ? 'Test reminder sent to ' + to : ('Not sent: ' + (r.reason || r.status)), r.sent ? 'success' : 'error');
    } catch (err) { toast(err.message || 'Could not send', 'error'); }
  }

  async function paintEvent(main, body, id) {
    var d, rep;
    try { d = await Api.get('/admin/rsvp/events/' + id); rep = await Api.get('/admin/rsvp/events/' + id + '/report?view=all'); }
    catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }
    if (!main.isConnected) { return; }
    var ev = d.event, s = ev.stats || {}, pa = primaryFor(ev);
    var stat = function (n, l) { return '<div class="rv-stat"><b>' + (n || 0) + '</b><span>' + l + '</span></div>'; };
    var btn = function (attr, label, cls) { return '<button type="button" class="rv-btn' + (cls ? ' ' + cls : '') + '" data-kt-iconized="1" ' + attr + '>' + label + '</button>'; };
    var sec = function (title, actions, inner, full) { return '<section class="rv-sec' + (full ? ' full' : '') + '"><header><h4>' + title + '</h4>' + actions + '</header>' + inner + '</section>'; };
    var dl = function (pairs) { return '<dl class="rv-dl">' + pairs.filter(function (p) { return p; }).map(function (p) { return '<dt>' + p[0] + '</dt><dd>' + p[1] + '</dd>'; }).join('') + '</dl>'; };
    var snd = ev.sender_id ? state.senders.filter(function (x) { return x.id === +ev.sender_id; })[0] : null;
    var df = state.defaultFrom || {};
    var fromTxt = snd ? (snd.from_name ? esc(snd.from_name) + ' &lt;' + esc(snd.from_address) + '&gt;' : esc(snd.from_address))
      : (ev.branding === 'none' ? esc(ev.title) : esc(df.name || '')) + ' &lt;' + esc(df.address || '') + '&gt;';
    var footHint = ev.status === 'draft' ? 'Draft — the RSVP link isn’t live yet.'
      : ev.status === 'closed' ? 'Closed — no more RSVPs.'
      : (s.pending ? s.pending + ' waiting for an invitation.' : 'Open — taking RSVPs.');
    body.innerHTML = '<div class="rv-bar">' + btn('data-back', '← All events', 'ghost') + '<span style="flex:1"></span>'
      + btn('data-refresh title="Refresh"', '↻ Refresh') + '<button type="button" class="kt-ka-kebab" data-kt-iconized="1" data-menu title="More actions" aria-label="More actions">⋮</button></div>'
      + '<div class="rv-head">' + (ev.image_url ? '<img src="' + esc(ev.image_url) + '" alt="">' : '')
      + '<div style="flex:1;min-width:220px"><h3>' + esc(ev.title) + ' ' + statusPill(ev.status) + (ev.branding === 'none' ? ' <span class="rv-pill" style="background:#F1F5F9;color:#475569">Unbranded</span>' : '') + '</h3>'
      + (ev.when ? '<div class="rv-sub">📅 ' + esc(ev.when) + '</div>' : '') + (ev.location ? '<div class="rv-sub">📍 ' + esc(ev.location) + '</div>' : '')
      + '<div class="rv-link"><code>' + esc(ev.public_url) + '</code>' + btn('data-copy="' + esc(ev.public_url) + '"', 'Copy link')
      + '<a class="rv-btn" data-kt-iconized="1" href="' + esc(ev.public_url) + '" target="_blank" rel="noopener">Open page</a></div></div></div>'
      + '<div class="rv-stats">' + stat(s.invited, 'Invited') + stat(s.sent, 'Sent') + stat((s.pending || 0) + (s.queued || 0), 'Waiting to send') + stat(s.not_delivered, 'Not delivered')
      + stat(s.email_opened, 'Opened email') + stat(s.opened, 'Clicked link') + stat(s.registered, 'Signed up') + stat(s.opted_out, 'Opted out') + stat(s.no_response, 'No response') + '</div>'
      + (s.queued ? '<div class="rv-hint" style="margin:-4px 0 10px">' + s.queued + ' invitation(s) are going out now — they are sent within about a minute. Press Refresh to see the result.</div>' : '')
      + '<div class="rv-secs">'
      + sec('Details', btn('data-edit', 'Edit'), dl([['When', esc(ev.when || 'No date set')], ['Where', esc(ev.location || '—')],
          ['Capacity', ev.capacity ? esc(ev.capacity) : 'No limit'], ['Status', statusPill(ev.status)], ['Sign-ups go to', esc(ev.notify_email)]]))
      + sec('Questions', btn('data-qs', 'Edit questions'), '<div class="rv-qchips">' + qChips(ev.fields) + '</div>')
      + sec('Email & image', btn('data-edit', 'Edit') + btn('data-preview', 'Preview'),
          '<div class="rv-mail">' + (ev.image_url ? '<img src="' + esc(ev.image_url) + '" alt="">' : '') + '<div class="tx"><b>' + esc(ev.email_subject) + '</b><p>' + esc(ev.email_body) + '</p></div></div>')
      + sec('Sender & branding', btn('data-edit', 'Change') + btn('data-test', 'Test invitation') + btn('data-testconf', 'Test confirmation'), dl([['From', fromTxt], ['Account', snd ? esc(snd.label) : 'Agency default email'],
          ['Reply-To', esc(ev.notify_email)], ['Branding', ev.branding === 'none' ? 'Unbranded' : 'Agency branded']]))
      + sec('Reminders', btn('data-remnow', '🔔 Send a reminder now'), '<div data-remhost><div class="rv-hint">Loading reminders…</div></div>', true)
      + sec('Invitees', btn('data-add', '＋ Add invitees') + btn('data-report', 'Full report'), inviteTable(rep.rows || []), true)
      + '</div>'
      + '<div class="rv-foot"><span class="hint">' + esc(footHint) + '</span>' + btn('data-back', 'Back', 'ghost') + btn('data-preview', '👁 Preview')
      + btn('data-pa="' + pa[0] + '"', esc(pa[1]), 'pri') + '</div>';
    pinFooter(main);
    var remHost = body.querySelector('[data-remhost]');
    if (remHost) { loadReminders(main, remHost, ev); }
    function picked() { return Array.prototype.slice.call(body.querySelectorAll('[data-pick]:checked')); }
    function syncBulk() {
      var bar = body.querySelector('[data-bulkbar]'); if (!bar) { return; }
      var all = body.querySelectorAll('[data-pick]'), n = picked().length, sa = body.querySelector('[data-selall]');
      bar.style.display = n ? 'flex' : 'none';
      bar.querySelector('[data-bulkn]').textContent = n + ' selected';
      if (sa) { sa.checked = n > 0 && n === all.length; sa.indeterminate = n > 0 && n < all.length; }
    }
    body.onchange = function (e) {
      var t = e.target;
      if (t.hasAttribute && t.hasAttribute('data-selall')) {
        body.querySelectorAll('[data-pick]').forEach(function (c) { c.checked = t.checked; });
      }
      if (t.hasAttribute && (t.hasAttribute('data-selall') || t.hasAttribute('data-pick'))) { syncBulk(); }
    };
    body.onclick = async function (e) {
      var b = e.target.closest('button,a[data-copy]'); if (!b) { return; }
      if (b.hasAttribute('data-bulkclear')) {
        body.querySelectorAll('[data-pick],[data-selall]').forEach(function (c) { c.checked = false; c.indeterminate = false; });
        syncBulk(); return;
      }
      if (b.hasAttribute('data-bulkresend')) {
        var ids = picked().map(function (c) { return +c.getAttribute('data-pick'); });
        if (!ids.length) { return; }
        var okB = await KT.confirm({ title: 'Send the invitation again to ' + ids.length + (ids.length === 1 ? ' person?' : ' people?'),
          description: 'Each of them gets another copy of the invitation. People who have signed up or opted out are never included.', okLabel: 'Send ' + ids.length });
        if (!okB) { return; }
        try {
          var rb = await Api.post('/admin/rsvp/events/' + ev.id + '/send', { invite_ids: ids });
          toast(rb.queued ? (rb.queued + ' invitation' + (rb.queued === 1 ? '' : 's') + ' queued — they go out within a few minutes') : 'Nothing to send', rb.queued ? 'success' : 'info');
          paint(main);
        } catch (err) { toast(err.message || 'Could not send', 'error'); }
        return;
      }
      if (b.hasAttribute('data-back')) { state.eventId = null; paint(main); }
      else if (b.hasAttribute('data-refresh')) { paint(main); }
      else if (b.hasAttribute('data-menu')) { e.stopPropagation(); openMenu(b, eventMenu(main, ev, true)); }
      else if (b.hasAttribute('data-copy')) { copy(b.getAttribute('data-copy')); }
      else if (b.hasAttribute('data-edit')) { eventDialog(main, ev); }
      else if (b.hasAttribute('data-qs')) { questionsDialog(main, ev); }
      else if (b.hasAttribute('data-add')) { inviteDialog(main, ev); }
      else if (b.hasAttribute('data-report')) { state.tab = 'reports'; state.reportEvent = ev.id; state.view = 'all'; state.eventId = null; paint(main); }
      else if (b.hasAttribute('data-preview')) { previewDialog({ event_id: ev.id }); }
      else if (b.hasAttribute('data-pa')) { runPrimary(main, ev, b.getAttribute('data-pa')); }
      else if (b.hasAttribute('data-test')) { sendTest(ev); }
      else if (b.hasAttribute('data-testconf')) { sendTestConfirmation(ev); }
      else if (b.hasAttribute('data-remnow')) { sendReminderNow(ev, null, function () { var h = body.querySelector('[data-remhost]'); if (h) { loadReminders(main, h, ev); } }); }
      else if (b.hasAttribute('data-resend')) {
        var ok = await KT.confirm({ title: 'Send the invitation again?', description: 'This sends ' + b.getAttribute('data-email') + ' another copy of the invitation.', okLabel: 'Send again' });
        if (!ok) { return; }
        try { var r = await Api.post('/admin/rsvp/events/' + ev.id + '/send', { invite_ids: [+b.getAttribute('data-resend')] }); toast(r.queued ? 'Invitation queued' : 'Nothing to send', r.queued ? 'success' : 'info'); paint(main); }
        catch (err) { toast(err.message || 'Could not send', 'error'); }
      } else if (b.hasAttribute('data-remove')) {
        try { await Api.delete('/admin/rsvp/invites/' + b.getAttribute('data-remove')); toast('Removed from the list', 'success'); paint(main); }
        catch (err) { toast(err.message || 'Could not remove', 'error'); }
      }
    };
  }

  /* "Email opened": the first open, and how many times when more than once. Opens come
     from the image in the email, so they are approximate — a mail app that blocks images
     never reports, and Apple Mail reports every message as opened. */
  function openedCell(r) {
    if (!r.email_opened_at) { return '—'; }
    return esc(r.email_opened_at) + (r.email_opens > 1 ? '<div class="rv-hint">' + r.email_opens + ' times</div>' : '');
  }

  function remindedCell(r) {
    if (!r.reminded_at) { return '—'; }
    return esc(r.reminded_at) + (r.reminders > 1 ? '<div class="rv-hint">' + r.reminders + ' reminders</div>' : '');
  }

  function inviteTable(rows) {
    if (!rows.length) { return '<div class="kt-card" style="color:#64748B">Nobody on the list yet. Add invitees, or share the link — people who use it appear here too.</div>'; }
    /* SEVERAL AT ONCE (Anthony, 2026-10-05: "add a multiple option to select multiple
       invitee's in the table to resend the email"). A tick only on rows that CAN be sent
       again — the same rule as the row's Resend button and the server: not someone who has
       signed up or opted out. The header box selects all of those. */
    var canResend = function (r) { return r.status !== 'not_invited' && !r.registered_at && !r.opted_out_at && (r.status === 'sent' || r.status === 'failed' || r.status === 'suppressed'); };
    var anyResend = rows.some(canResend);
    return (anyResend ? '<div class="rv-bar rv-bulk" data-bulkbar style="display:none"><b data-bulkn>0 selected</b>'
        + '<button type="button" class="rv-btn pri" data-kt-iconized="1" data-bulkresend>↻ Resend invitation</button>'
        + '<button type="button" class="rv-btn" data-kt-iconized="1" data-bulkclear>Clear</button></div>' : '')
      + '<div class="kt-card"><table><thead><tr>'
      + (anyResend ? '<th style="width:30px"><input type="checkbox" data-selall title="Select everyone who can be sent the invitation again" aria-label="Select all"></th>' : '')
      + '<th>Name</th><th>Email</th><th>Invitation</th><th>Sent</th>'
      + '<th title="When the invitation email was first opened (from the image in the email — approximate)">Email opened</th>'
      + '<th title="When they opened the RSVP page from the invitation">Clicked RSVP link</th><th>Signed up</th><th>Opted out</th><th title="The last reminder sent to them">Reminded</th><th></th></tr></thead><tbody>'
      + rows.map(function (r) {
        var acts = '';
        if (canResend(r)) {
          acts += '<button type="button" class="kt-btn" data-resend="' + r.id + '" data-email="' + esc(r.email) + '" title="Send the invitation again">Resend</button>';
        }
        if (r.can_remove) { acts += '<button type="button" class="kt-btn" data-remove="' + r.id + '" title="Remove from the list">Remove</button>'; }
        return '<tr>' + (anyResend ? '<td>' + (canResend(r) ? '<input type="checkbox" data-pick="' + r.id + '" aria-label="Select ' + esc(r.email) + '">' : '') + '</td>' : '')
          + '<td>' + esc(r.name || '—') + '</td><td>' + esc(r.email) + '</td><td>' + sendPill(r.status, r.status_label)
          + (r.note ? '<div class="rv-hint" title="' + esc(r.note) + '">' + esc(r.note.length > 70 ? r.note.slice(0, 70) + '…' : r.note) + '</div>' : '') + '</td>'
          + '<td>' + esc(r.sent_at || '—') + '</td><td>' + openedCell(r) + '</td><td>' + esc(r.opened_at || '—') + '</td>'
          + '<td>' + esc(r.registered_at || '—') + '</td><td>' + esc(r.opted_out_at || '—') + '</td>'
          + '<td>' + remindedCell(r) + '</td>'
          + '<td style="text-align:center;white-space:nowrap">' + acts + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  async function sendTestConfirmation(ev) {
    var to = await KT.prompt({ title: 'Send a test confirmation', description: 'Sends one copy of the email a registrant receives after signing up — with the map and tips — marked [Test], with sample answers. Nothing is recorded as an RSVP.', placeholder: 'you@example.com', type: 'email', okLabel: 'Send test' });
    if (to === null) { return; }
    to = String(to || '').trim();
    if (!isEmail(to)) { toast('Enter an email address', 'error'); return; }
    try {
      var r = await Api.post('/admin/rsvp/events/' + ev.id + '/test-confirmation', { to: to });
      toast(r.sent ? 'Test confirmation sent to ' + to : ('Not sent: ' + (r.reason || r.status)), r.sent ? 'success' : 'error');
    } catch (err) { toast(err.message || 'Could not send', 'error'); }
  }

  async function sendTest(ev) {
    var to = await KT.prompt({ title: 'Send a test invitation', description: 'Sends one copy of this event’s invitation, marked [Test], to the address you enter. Its buttons open the shareable page, so nobody is signed up by it.', placeholder: 'you@example.com', type: 'email', okLabel: 'Send test' });
    if (to === null) { return; }
    to = String(to || '').trim();
    if (!isEmail(to)) { toast('Enter a valid email address', 'error'); return; }
    try {
      var r = await Api.post('/admin/rsvp/events/' + ev.id + '/test', { to: to });
      if (r.sent) { toast('Test sent to ' + to, 'success'); }
      else { toast('Not sent (' + r.status + '): ' + (r.reason || ''), 'warning'); }
    } catch (e) { toast(e.message || 'Could not send', 'error'); }
  }

  function questionsDialog(main, ev) {
    var ov = dialog('Questions — ' + ev.title, '<div class="rv-f">' + questionsBlock(ev.fields) + '</div>', async function (root) {
      var fields = readQuestions(root);
      await Api.post('/admin/rsvp/events/' + ev.id, { title: ev.title, starts_local: ev.starts_local || null, ends_local: ev.ends_local || null, location: ev.location,
        description: ev.description, notify_email: ev.notify_email, status: ev.status, capacity: ev.capacity, fields: fields });
      toast('Questions saved', 'success'); paint(main);
    }, 'Save questions', { width: 820 });
    wireQuestions(ov);
  }

  /* ── Add invitees: paste / CSV, or pick families and staff ── */
  function parseList(text) {
    var out = [], lines = String(text || '').split(/\r?\n/), header = null;
    lines.forEach(function (line, i) {
      if (!line.trim()) { return; }
      var cells = csvCells(line);
      if (i === 0 && !cells.some(isEmail) && cells.some(function (c) { return /mail/i.test(c); })) { header = cells.map(function (c) { return c.toLowerCase(); }); return; }
      var email = '', name = '';
      if (header) {
        var ei = header.findIndex(function (h) { return /mail/.test(h); });
        var ni = header.findIndex(function (h) { return /^(full )?name$/.test(h.trim()); });
        var fi = header.findIndex(function (h) { return /first/.test(h); }), li = header.findIndex(function (h) { return /last|surname/.test(h); });
        email = cells[ei] || '';
        name = ni >= 0 ? cells[ni] : [fi >= 0 ? cells[fi] : '', li >= 0 ? cells[li] : ''].join(' ');
      } else {
        // "Jane Doe <jane@x.ca>", "Jane Doe, jane@x.ca", or just "jane@x.ca"
        var m = line.match(/<([^>]+)>/);
        email = m ? m[1] : (cells.filter(isEmail)[0] || '');
        name = m ? line.replace(/<[^>]+>/, '') : cells.filter(function (c) { return !isEmail(c); }).join(' ');
      }
      out.push({ email: String(email).trim(), name: String(name || '').replace(/["']/g, '').trim() });
    });
    return out;
  }
  function csvCells(line) {
    var out = [], cur = '', q = false;
    for (var i = 0; i < line.length; i++) {
      var c = line[i];
      if (c === '"') { if (q && line[i + 1] === '"') { cur += '"'; i++; } else { q = !q; } }
      else if ((c === ',' || c === ';' || c === '\t') && !q) { out.push(cur.trim()); cur = ''; }
      else { cur += c; }
    }
    out.push(cur.trim());
    return out;
  }

  async function inviteDialog(main, ev) {
    var contacts = [];
    var html = '<div class="rv-tabs" data-itabs><button data-it="paste" class="on">Paste or upload</button><button data-it="pick">Families &amp; staff</button></div>'
      + '<div data-pane="paste"><div class="rv-f"><div class="full"><label>One person per line</label>'
      + '<textarea data-paste rows="8" placeholder="Jane Doe, jane@example.com&#10;sam@example.com&#10;Or paste a CSV with an Email column"></textarea>'
      + '<div class="rv-hint">Accepts “Name, email”, “Name &lt;email&gt;”, a bare email, or a CSV with a header row (Email, Name / First name, Last name).</div></div>'
      + '<div class="full"><input type="file" accept=".csv,text/csv,text/plain" data-csv style="display:none"><button type="button" class="rv-btn" data-kt-iconized="1" data-csvbtn>Upload a CSV file</button> <span data-pcount class="rv-hint"></span></div></div></div>'
      + '<div data-pane="pick" style="display:none"><div class="rv-bar"><input data-search placeholder="Search names or emails" style="flex:1;min-width:160px">'
      + '<select data-kind><option value="">Everyone</option><option value="guardian">Families</option><option value="staff">Staff</option></select>'
      + '<button type="button" class="rv-btn" data-kt-iconized="1" data-all>Select shown</button><button type="button" class="rv-btn" data-kt-iconized="1" data-none>Clear</button></div>'
      + '<div class="rv-pick" data-list>Loading…</div><div class="rv-hint" data-scount></div></div>'
      + '<label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-weight:700;font-size:13px"><input type="checkbox" data-sendnow' + (ev.status === 'open' ? ' checked' : ' disabled') + '> Send their invitations now'
      + (ev.status === 'open' ? '' : ' <span class="rv-hint">(open the event first)</span>') + '</label>';
    var picked = {};
    var ov = dialog('Add invitees — ' + ev.title, html, async function (root) {
      var list = parseList(root.querySelector('[data-paste]').value).map(function (p) { return { email: p.email, name: p.name, source: csvLoaded ? 'csv' : 'manual' }; });
      Object.keys(picked).forEach(function (k) { var c = picked[k]; list.push({ source: c.kind, user_id: c.user_id, email: c.email, name: c.name }); });
      if (!list.length) { throw new Error('Add at least one person.'); }
      var r = await Api.post('/admin/rsvp/events/' + ev.id + '/invites', { recipients: list, send: root.querySelector('[data-sendnow]').checked });
      var msg = r.added + ' added' + (r.queued ? ', ' + r.queued + ' invitation(s) on their way' : '') + (r.already_invited ? ' · ' + r.already_invited + ' already on the list' : '')
        + (r.opted_out ? ' · ' + r.opted_out + ' opted out (won’t be sent)' : '') + (r.invalid_count ? ' · ' + r.invalid_count + ' not valid: ' + r.invalid.slice(0, 5).join(', ') : '');
      toast(msg, r.invalid_count ? 'warning' : 'success');
      paint(main);
    }, 'Add invitees', { width: 760 });
    var csvLoaded = false;
    var ta = ov.querySelector('[data-paste]'), pc = ov.querySelector('[data-pcount]');
    var count = function () { var l = parseList(ta.value); var ok = l.filter(function (p) { return isEmail(p.email); }).length; pc.textContent = l.length ? ok + ' valid email' + (ok === 1 ? '' : 's') + (l.length - ok ? ' · ' + (l.length - ok) + ' line(s) without one' : '') : ''; };
    ta.addEventListener('input', count);
    var file = ov.querySelector('[data-csv]');
    ov.querySelector('[data-csvbtn]').onclick = function () { file.click(); };
    file.onchange = function () {
      var f = file.files && file.files[0]; if (!f) { return; }
      var rd = new FileReader(); rd.onload = function () { ta.value = String(rd.result || '').replace(/^﻿/, ''); csvLoaded = true; count(); }; rd.readAsText(f);
    };
    ov.querySelector('[data-itabs]').onclick = async function (e) {
      var b = e.target.closest('[data-it]'); if (!b) { return; }
      ov.querySelectorAll('[data-it]').forEach(function (x) { x.classList.toggle('on', x === b); });
      ov.querySelectorAll('[data-pane]').forEach(function (p) { p.style.display = p.getAttribute('data-pane') === b.getAttribute('data-it') ? '' : 'none'; });
      if (b.getAttribute('data-it') === 'pick' && !contacts.length) {
        try { contacts = (await Api.get('/admin/rsvp/contacts')).contacts || []; } catch (err) { ov.querySelector('[data-list]').textContent = err.message || 'Could not load contacts'; return; }
        drawPick();
      }
    };
    var shown = [];
    function drawPick() {
      var q = ov.querySelector('[data-search]').value.trim().toLowerCase(), kind = ov.querySelector('[data-kind]').value;
      shown = contacts.filter(function (c) { return (!kind || c.kind === kind) && (!q || (c.name + ' ' + c.email + ' ' + c.detail).toLowerCase().indexOf(q) >= 0); });
      var box = ov.querySelector('[data-list]');
      box.innerHTML = shown.length ? shown.slice(0, 500).map(function (c) {
        return '<label><input type="checkbox" data-c="' + esc(c.key) + '"' + (picked[c.key] ? ' checked' : '') + '><span><strong>' + esc(c.name || c.email) + '</strong> <small>' + esc(c.email) + (c.detail ? ' · ' + esc(c.detail) : '') + '</small></span></label>';
      }).join('') : '<div style="padding:10px;color:#64748B">Nobody matches.</div>';
      ov.querySelector('[data-scount]').textContent = Object.keys(picked).length + ' selected';
    }
    ov.querySelector('[data-search]').addEventListener('input', drawPick);
    ov.querySelector('[data-kind]').addEventListener('change', drawPick);
    ov.querySelector('[data-list]').addEventListener('change', function (e) {
      var k = e.target.getAttribute('data-c'); if (!k) { return; }
      var c = contacts.filter(function (x) { return x.key === k; })[0];
      if (e.target.checked && c) { picked[k] = c; } else { delete picked[k]; }
      ov.querySelector('[data-scount]').textContent = Object.keys(picked).length + ' selected';
    });
    ov.querySelector('[data-all]').onclick = function () { shown.slice(0, 500).forEach(function (c) { picked[c.key] = c; }); drawPick(); };
    ov.querySelector('[data-none]').onclick = function () { picked = {}; drawPick(); };
  }

  /* ── Templates: the built-ins every agency has, then your own ── */
  function paintTemplates(main, body) {
    var rows = state.templates;
    body.innerHTML = '<h3 class="rv-h">Built-in templates</h3><div class="rv-hint" style="margin:-2px 0 10px">Ready to use — start an event from one straight away. They can’t be changed; <b>Customize</b> saves a copy under Your templates to edit.</div>'
      + '<div class="rv-presets">' + state.presets.map(function (p) {
        return '<div class="rv-preset"><img src="' + esc(p.image_url) + '" alt="" loading="lazy"><div class="in"><b>' + esc(p.name) + '</b><div class="rv-hint">' + esc(p.description) + '</div>'
          + '<div class="rv-hint" style="margin-top:4px">Asks: ' + esc(p.fields.map(function (f) { return f.label; }).join(', ')) + '</div>'
          + (p.branding === 'none' ? '<div class="rv-hint">🏷️ Unbranded by default</div>' : '')
          + '<div class="acts"><button type="button" class="rv-btn pri" data-kt-iconized="1" data-puse="' + esc(p.key) + '">Use for an event</button>'
          + '<button type="button" class="rv-btn" data-kt-iconized="1" data-ppv="' + esc(p.key) + '">Preview</button><button type="button" class="rv-btn" data-kt-iconized="1" data-pcopy="' + esc(p.key) + '">Customize</button></div></div></div>';
      }).join('') + '</div>'
      + '<div class="rv-bar" style="margin-top:20px"><h3 class="rv-h" style="margin:0;flex:1">Your templates</h3><button class="rv-btn" data-kt-iconized="1" data-new>＋ New template</button></div>'
      + (rows.length ? '<div class="kt-card"><table><thead><tr><th>Template</th><th>Questions</th><th>Sign-ups go to</th><th>Events</th><th></th></tr></thead><tbody>'
        + rows.map(function (t) {
          return '<tr><td><strong>' + esc(t.name) + '</strong>' + (t.description ? '<div class="rv-hint">' + esc(t.description) + '</div>' : '') + '</td>'
            + '<td>' + esc(t.fields.map(function (f) { return f.label; }).join(', ')) + '</td><td>' + esc(t.notify_email || '—') + '</td><td>' + t.events + '</td>'
            + '<td style="text-align:center;white-space:nowrap"><button type="button" class="kt-btn" data-use="' + t.id + '" title="Start an event from this template">Use</button>'
            + '<button type="button" class="kt-btn" data-pv="' + t.id + '" title="Preview">Preview</button>'
            + '<button type="button" class="kt-btn" data-edit="' + t.id + '" title="Edit">Edit</button>'
            + '<button type="button" class="kt-btn" data-dup="' + t.id + '" title="Duplicate">Duplicate</button>'
            + (state.isPlatform ? '<button type="button" class="kt-btn" data-copyto="' + t.id + '" title="Copy to another agency">Copy to agency</button>' : '')
            + '<button type="button" class="kt-btn" data-del="' + t.id + '" title="Delete template">Delete</button></td></tr>';
        }).join('') + '</tbody></table></div>'
        : '<div class="kt-card" style="color:#64748B">No templates of your own yet. Customize a built-in, or make one from scratch.</div>');
    body.querySelector('[data-new]').onclick = function () { templateDialog(main, null); };
    body.onclick = async function (e) {
      var b = e.target.closest('button'); if (!b) { return; }
      if (b.hasAttribute('data-puse')) { state.tab = 'events'; state.eventId = null; await paint(main); eventDialog(main, null, 'p:' + b.getAttribute('data-puse')); return; }
      if (b.hasAttribute('data-ppv')) { previewDialog({ preset: b.getAttribute('data-ppv') }); return; }
      if (b.hasAttribute('data-pcopy')) {
        var p = state.presets.filter(function (x) { return x.key === b.getAttribute('data-pcopy'); })[0] || {};
        var name = await KT.prompt({ title: 'Customize “' + p.name + '”', description: 'Saves a copy under Your templates that you can change. The built-in stays as it is.', value: p.name, placeholder: 'Template name', okLabel: 'Save my copy' });
        if (name === null) { return; }
        try {
          var r = await Api.post('/admin/rsvp/presets/' + encodeURIComponent(p.key) + '/copy', { name: String(name || '').trim() || p.name });
          toast('Saved to your templates', 'success'); await paint(main);
          var t0 = state.templates.filter(function (x) { return x.id === r.id; })[0]; if (t0) { templateDialog(main, t0); }
        } catch (err) { toast(err.message || 'Could not copy', 'error'); }
        return;
      }
      var id = +(b.getAttribute('data-use') || b.getAttribute('data-pv') || b.getAttribute('data-edit') || b.getAttribute('data-dup') || b.getAttribute('data-copyto') || b.getAttribute('data-del') || 0);
      var t = rows.filter(function (x) { return x.id === id; })[0]; if (!t) { return; }
      if (b.hasAttribute('data-use')) { state.tab = 'events'; state.eventId = null; await paint(main); eventDialog(main, null, 't:' + t.id); }
      else if (b.hasAttribute('data-pv')) { previewDialog({ template_id: t.id }); }
      else if (b.hasAttribute('data-edit')) { templateDialog(main, t); }
      else if (b.hasAttribute('data-dup')) {
        try { await Api.post('/admin/rsvp/templates/' + id + '/copy', {}); toast('Template duplicated', 'success'); paint(main); } catch (err) { toast(err.message || 'Could not copy', 'error'); }
      } else if (b.hasAttribute('data-copyto')) {
        copyToAgency(main, t);
      } else if (b.hasAttribute('data-del')) {
        var ok = await KT.confirm({ title: 'Delete “' + t.name + '”?', description: 'Events already made from it keep their own questions and email, so nothing about them changes.', okLabel: 'Delete template', tone: 'danger' });
        if (!ok) { return; }
        try { await Api.delete('/admin/rsvp/templates/' + id); toast('Template deleted', 'success'); paint(main); } catch (err) { toast(err.message || 'Could not delete', 'error'); }
      }
    };
  }

  /* ── Sender accounts: which mailbox an event's email comes from ──
     This hosting blocks outbound mail servers (SMTP) altogether, so a sender is a Gmail
     account CONNECTED BY SIGNING IN with Google; mail then goes through the Gmail API over
     HTTPS. A custom SMTP server is kept behind "Advanced" for a future host that allows it. */
  var GMAIL_REASONS = {
    denied: 'Google sign-in was cancelled.',
    state: 'That sign-in link expired or was already used. Press Connect Gmail again.',
    exchange: 'Google didn’t complete the sign-in. Please try again.',
    google: 'Google didn’t complete the sign-in. Please try again.',
    no_refresh: 'Google didn’t grant lasting access. Remove the app at myaccount.google.com/permissions, then connect again.',
    scope: 'Permission to send email wasn’t granted. Connect again and allow “Send email on your behalf”.',
    no_email: 'Google didn’t confirm the email address. Please try again.',
    sender_gone: 'That sender account no longer exists.',
    not_configured: 'Gmail connection isn’t set up yet — the platform admin needs to add a Google client.'
  };
  /* Back from Google: ?rsvp_gmail=<result> on the dashboard URL. Say what happened, open the
     Sender accounts tab, and take the flag off the address so a reload does not repeat it. */
  function gmailReturn() {
    var q; try { q = new URLSearchParams(location.search); } catch (e) { return; }
    var r = q.get('rsvp_gmail'); if (!r) { return; }
    state.tab = 'senders'; state.eventId = null;
    if (r === 'connected') { toast('Gmail connected — sending as ' + (q.get('email') || 'that account') + (q.get('was') ? ' (was ' + q.get('was') + ')' : ''), 'success'); }
    else { toast(GMAIL_REASONS[r] || 'Gmail could not be connected.', 'error'); }
    ['rsvp_gmail', 'email', 'was'].forEach(function (k) { q.delete(k); });
    try { history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q.toString() : '') + location.hash); } catch (e) {}
  }
  async function connectGmail(s) {
    var gm = state.gmail || {};
    if (!gm.configured) { toast(GMAIL_REASONS.not_configured, 'error'); return; }
    var r;
    try { r = await Api.get('/admin/rsvp/senders/' + s.id + '/google/start'); }
    catch (e) { toast(e.message || 'Could not start the Google sign-in', 'error'); return; }
    /* Google refuses to sign in inside an app's embedded browser, so in the phone app the
       sign-in opens in the system browser tab; on a computer it is this tab. */
    var C = window.Capacitor, B = C && C.Plugins && C.Plugins.Browser;
    if (window.KT && KT.isNativeApp && KT.isNativeApp() && B && B.open) { B.open({ url: r.url }); return; }
    location.href = r.url;
  }

  var MAIL_HELP = '<div class="rv-help"><b>Connect Gmail</b> signs in with the Gmail account the invitations should come from — a Google Workspace address works the same way. KiddieTrac only asks to <b>send</b> email for it; it can’t read the mailbox. Mail is sent from that address, and you can disconnect at any time from your Google Account → Security → Third-party connections.</div>';
  var SMTP_HELP = '<div class="rv-warn" style="margin:0">Custom mail servers (SMTP) can’t send from KiddieTrac’s current hosting — outbound connections to mail servers are blocked, so this kind of account would only time out. Use <b>Connect Gmail</b> instead. These settings are kept for a future host that allows them.</div>';

  function senderStatus(s) {
    if (s.type === 'gmail_oauth') {
      if (s.google_status === 'connected') { return '<span class="rv-pill" style="background:#DCFCE7;color:#166534">Connected</span><div class="rv-hint">as ' + esc(s.google_email || s.from_address) + (s.google_connected_at ? ' · since ' + esc(s.google_connected_at) : '') + '</div>'; }
      if (s.google_status === 'needs_reconnect') { return '<span class="rv-pill" style="background:#FEE2E2;color:#991B1B">Needs reconnecting</span>' + (s.last_test_error ? '<div class="rv-hint">' + esc(s.last_test_error) + '</div>' : ''); }
      return '<span class="rv-pill" style="background:#F1F5F9;color:#475569">Not connected</span><div class="rv-hint">Press Connect Gmail to sign in.</div>';
    }
    return '<span class="rv-pill" style="background:#FEF3C7;color:#92400E">Custom server</span><div class="rv-hint">Can’t send from this hosting</div>';
  }

  async function paintSenders(main, body) {
    var d;
    try { d = await Api.get('/admin/rsvp/senders'); } catch (e) { body.innerHTML = '<div class="kt-card" style="color:#B91C1C">Could not load: ' + esc(e.message || e) + '</div>'; return; }
    if (!main.isConnected) { return; }
    var rows = d.senders || [], df = state.defaultFrom || {}, gm = (state.gmail = d.gmail || state.gmail || {});
    body.innerHTML = '<div class="rv-bar"><h3 class="rv-h" style="margin:0;flex:1">Sender accounts</h3><button type="button" class="rv-btn" data-kt-iconized="1" data-new>＋ Add Gmail sender</button></div>'
      + '<div class="rv-hint" style="margin-bottom:10px">Each event can send from its own Gmail — say, a town account and the centre’s own. Events with no account chosen use the agency’s default email' + (df.address ? ' (' + esc(df.address) + ')' : '') + '.</div>'
      + (gm.configured ? '' : '<div class="rv-warn">' + esc(GMAIL_REASONS.not_configured) + ' It needs this redirect URI on the Google OAuth client: <code>' + esc(gm.redirect_uri || '') + '</code></div>')
      + (rows.length ? '<div class="kt-card"><table><thead><tr><th>Account</th><th>Sends as</th><th>Connection</th><th>Events</th><th></th></tr></thead><tbody>'
        + rows.map(function (s) {
          var g = s.type === 'gmail_oauth';
          return '<tr><td><strong>' + esc(s.label) + '</strong><div class="rv-hint">' + (g ? 'Gmail' : 'Custom mail server') + '</div></td>'
            + '<td>' + esc(s.from_name || '') + (s.from_address ? ' &lt;' + esc(s.from_address) + '&gt;' : (g ? '<span class="rv-hint">set when connected</span>' : '')) + (s.reply_to ? '<div class="rv-hint">Reply-To ' + esc(s.reply_to) + '</div>' : '') + '</td>'
            + '<td>' + senderStatus(s) + '</td><td>' + (s.events || 0) + '</td>'
            + '<td style="text-align:center;white-space:nowrap">'
            + (g ? '<button type="button" class="kt-btn" data-connect="' + s.id + '" title="Sign in with Google">' + (s.google_status === 'connected' ? 'Reconnect Gmail' : 'Connect Gmail') + '</button>' : '')
            + ((!g || s.google_status === 'connected') ? '<button type="button" class="kt-btn" data-check="' + s.id + '" title="Check the account still works, sending nothing">Check connection</button>' : '')
            + '<button type="button" class="kt-btn" data-test="' + s.id + '" title="Send one test message">Send test</button>'
            + '<button type="button" class="kt-btn" data-edit="' + s.id + '">Edit</button>'
            + '<button type="button" class="kt-btn" data-del="' + s.id + '" data-label="' + esc(s.label) + '">Delete</button></td></tr>';
        }).join('') + '</tbody></table></div>'
        : '<div class="rv-empty">No sender accounts yet. Every event sends from the agency’s default email. Add a Gmail sender to send from your own address.</div>')
      + '<div style="margin-top:12px">' + MAIL_HELP + '</div>';
    body.querySelector('[data-new]').onclick = function () { senderDialog(main, null); };
    body.onclick = async function (e) {
      var b = e.target.closest('button'); if (!b) { return; }
      var id = +(b.getAttribute('data-connect') || b.getAttribute('data-edit') || b.getAttribute('data-check') || b.getAttribute('data-test') || b.getAttribute('data-del') || 0);
      var s = rows.filter(function (x) { return x.id === id; })[0]; if (!s) { return; }
      if (b.hasAttribute('data-connect')) { connectGmail(s); }
      else if (b.hasAttribute('data-edit')) { senderDialog(main, s); }
      else if (b.hasAttribute('data-check')) {
        b.disabled = true;
        try { var r = await Api.post('/admin/rsvp/senders/' + id + '/check', {}); toast(r.ok ? '“' + s.label + '” works' : 'Not working: ' + (r.error || ''), r.ok ? 'success' : 'error'); }
        catch (err) { toast(err.message || 'Could not check', 'error'); }
        b.disabled = false; paint(main);
      } else if (b.hasAttribute('data-test')) {
        var to = await KT.prompt({ title: 'Send a test from “' + s.label + '”', description: 'Sends one short message through this account to the address you enter.', placeholder: 'you@example.com', type: 'email', okLabel: 'Send test' });
        if (to === null) { return; }
        to = String(to || '').trim();
        if (!isEmail(to)) { toast('Enter a valid email address', 'error'); return; }
        try { var t = await Api.post('/admin/rsvp/senders/' + id + '/test', { to: to }); toast(t.sent ? 'Test sent to ' + to : 'Not sent (' + t.status + '): ' + (t.reason || ''), t.sent ? 'success' : 'warning'); }
        catch (err) { toast(err.message || 'Could not send', 'error'); }
        paint(main);
      } else if (b.hasAttribute('data-del')) {
        var ok = await KT.confirm({ title: 'Delete “' + s.label + '”?', description: 'Its Google connection (or saved password) is erased. Events still open or in draft must be moved to another account first.', okLabel: 'Delete account', tone: 'danger' });
        if (!ok) { return; }
        try { await Api.delete('/admin/rsvp/senders/' + id); toast('Sender account deleted', 'success'); paint(main); } catch (err) { toast(err.message || 'Could not delete', 'error'); }
      }
    };
  }

  function senderDialog(main, s) {
    s = s || {};
    var smtp = s.id ? s.type !== 'gmail_oauth' : false;
    var html = '<div class="rv-f">'
      + field('label', 'Account name (for you)', s.label, { full: true, ph: 'e.g. Town of Mono events' })
      + field('from_name', 'Sender name', s.from_name, { ph: 'e.g. Town of Mono', hint: 'Shown as who the email is from.' })
      + field('reply_to', 'Default Reply-To (optional)', s.reply_to, { type: 'email', hint: 'Fills an event’s sign-up address when it is empty.' })
      + (smtp ? '' : '<div class="full">' + MAIL_HELP + (s.id ? '' : '<div class="rv-hint" style="margin-top:6px">After saving, you’ll be taken to Google to sign in with the Gmail account.</div>') + '</div>')
      + (s.id ? '' : '<div class="full"><label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" data-adv> Advanced: use a custom mail server (SMTP) instead</label></div>')
      + '<div class="full" data-smtp style="' + (smtp ? '' : 'display:none') + '"><div class="rv-f">'
      + '<div class="full">' + SMTP_HELP + '</div>'
      + field('from_address', 'Sender email address', s.from_address, { type: 'email', ph: 'events@yourorganisation.ca' })
      + field('smtp_host', 'Outgoing (SMTP) server', s.smtp_host, { ph: 'smtp.example.com' })
      + field('smtp_port', 'Port', s.smtp_port || 587, { type: 'number', min: 1 })
      + field('smtp_encryption', 'Security', s.smtp_encryption || 'tls', { select: [['tls', 'STARTTLS (usually port 587)'], ['ssl', 'SSL/TLS (usually port 465)']] })
      + field('smtp_user', 'Username', s.smtp_user, { ph: 'Usually the full email address' })
      + '<div class="full"><label>Password</label><input data-k="smtp_password" type="password" autocomplete="new-password" placeholder="' + (s.has_password ? '•••••••• saved — leave blank to keep it' : 'The account’s password') + '">'
      + '<div class="rv-hint">Stored encrypted and never shown again.</div></div></div></div>'
      + '</div>';
    var ov = dialog(s.id ? 'Edit sender account' : 'Add Gmail sender', html, async function (root) {
      var v = vals(root), useSmtp = smtp || (root.querySelector('[data-adv]') && root.querySelector('[data-adv]').checked);
      if (!v.label) { throw new Error('Give the account a name.'); }
      if (v.reply_to && !isEmail(v.reply_to)) { throw new Error('The Reply-To address is not a valid email.'); }
      var body = { type: useSmtp ? 'smtp' : 'gmail_oauth', label: v.label, from_name: v.from_name || null, reply_to: v.reply_to || null };
      if (useSmtp) {
        if (!isEmail(v.from_address)) { throw new Error('Enter the sender email address.'); }
        if (!v.smtp_host) { throw new Error('Enter the outgoing mail server.'); }
        body.from_address = v.from_address; body.smtp_host = v.smtp_host; body.smtp_port = +v.smtp_port || 587;
        body.smtp_encryption = v.smtp_encryption; body.smtp_user = v.smtp_user || null;
        if (v.smtp_password) { body.smtp_password = v.smtp_password; }
      }
      var r = await Api.post('/admin/rsvp/senders' + (s.id ? '/' + s.id : ''), body);
      if (!s.id && !useSmtp) {
        toast('Saved — now sign in with the Gmail account', 'success');
        if ((state.gmail || {}).configured) { connectGmail({ id: r.id }); return; }
        toast(GMAIL_REASONS.not_configured, 'warning');
      } else { toast('Sender account saved', 'success'); }
      paint(main);
    }, s.id ? 'Save account' : (((state.gmail || {}).configured) ? 'Save & connect Gmail' : 'Save'), { width: 720 });
    var adv = ov.querySelector('[data-adv]');
    if (adv) { adv.onchange = function () { ov.querySelector('[data-smtp]').style.display = adv.checked ? '' : 'none'; }; }
  }

  function copyToAgency(main, t) {
    var cur = String(sessionStorage.getItem('kt_active_agency_id') || '');
    var opts = state.agencies.filter(function (a) { return String(a.id) !== cur; }).map(function (a) { return [a.id, a.name]; });
    if (!opts.length) { toast('There is no other agency to copy to', 'info'); return; }
    dialog('Copy “' + t.name + '” to another agency', '<div class="rv-f">' + field('agency_id', 'Agency', opts[0][0], { select: opts, full: true,
      hint: 'The copy keeps the questions, email and image. Its sign-up address is left blank for that agency to set.' }) + '</div>', async function (root) {
      var v = vals(root);
      await Api.post('/admin/rsvp/templates/' + t.id + '/copy', { agency_id: +v.agency_id });
      toast('Copied to ' + (state.agencies.filter(function (a) { return String(a.id) === String(v.agency_id); })[0] || {}).name, 'success');
    }, 'Copy template');
  }

  function templateDialog(main, t) {
    t = t || {};
    var html = '<div class="rv-f">'
      + field('name', 'Template name', t.name, { full: true, ph: 'e.g. Community information session' })
      + field('description', 'Notes for your team (optional)', t.description, { full: true })
      + imageBlock(t.image_url)
      + field('email_subject', 'Invitation subject line', t.email_subject || '{{event}}', { full: true,
          hint: 'Your own subject line. {{event}} puts in the event title and {{first_name}} the person’s first name.' })
      + field('email_body', 'Invitation message', t.email_body || 'Hi {{first_name}},\n\nPlease let us know you’re coming to {{event}} by pressing RSVP below.\n\nWe hope to see you there.', { area: true, full: true, max: 8000, hint: PLACEHOLDER_HINT })
      + field('confirmation_message', 'Confirmation message (sent to each registrant with a copy of their answers)', t.confirmation_message || 'Thank you for registering for {{event}}. We look forward to seeing you.', { area: true, full: true })
      + field('notify_email', 'Send sign-ups and opt-outs to', t.notify_email, { type: 'email', full: true, ph: 'events@yourorganisation.ca', hint: 'Each event starts with this address and can change it.' })
      + field('branding', 'Branding (the default for events made from it)', t.branding || 'agency', { select: BRANDING, full: true, hint: BRANDING_HINT })
      + questionsBlock(t.fields) + '</div>';
    var ov = dialog(t.id ? 'Edit template' : 'New template', html, async function (root) {
      var v = vals(root);
      if (!v.name) { throw new Error('Give the template a name.'); }
      if (v.notify_email && !isEmail(v.notify_email)) { throw new Error('The sign-up address is not a valid email.'); }
      v.fields = readQuestions(root);
      if (!v.notify_email) { v.notify_email = null; }
      await Api.post('/admin/rsvp/templates' + (t.id ? '/' + t.id : ''), v);
      toast('Template saved', 'success'); paint(main);
    }, 'Save template', { width: 820, extra: [{ label: '👁 Preview', onClick: function (root) {
      var v = vals(root), d = { fields: readQuestions(root), title: v.name || null };
      ['image_url', 'email_subject', 'email_body', 'confirmation_message', 'notify_email', 'branding'].forEach(function (k) { d[k] = v[k] || null; });
      if (t.id) { d.template_id = t.id; }
      previewDialog(d);
    } }] });
    wireImage(ov); wireQuestions(ov);
  }

  /* ── Reports ── */
  async function paintReports(main, body) {
    var d = await Api.get('/admin/rsvp/events');
    var evs = d.events || [];
    if (!evs.length) { body.innerHTML = '<div class="kt-card" style="color:#64748B">No events yet.</div>' + await optOutsHtml(); wireOptOuts(main, body); return; }
    if (!state.reportEvent || !evs.some(function (e) { return e.id === state.reportEvent; })) { state.reportEvent = evs[0].id; }
    var rep = await Api.get('/admin/rsvp/events/' + state.reportEvent + '/report?view=' + encodeURIComponent(state.view));
    if (!main.isConnected) { return; }
    var s = rep.stats || {}, cols = rep.columns || [], rows = rep.rows || [];
    // Names the 📊 / 📄 / 📕 files after the event and the view, not "Event RSVPs".
    var evNow = evs.filter(function (e) { return e.id === state.reportEvent; })[0] || {};
    var viewLabel = (VIEWS.filter(function (v) { return v[0] === state.view; })[0] || [])[1];
    var exportTitle = (evNow.title || 'Event') + ' — RSVP report' + (state.view !== 'all' && viewLabel ? ' (' + viewLabel + ')' : '');
    var counts = { all: null, sent: s.sent, registered: s.registered, opted_out: s.opted_out, no_response: s.no_response, not_sent: (s.pending || 0) + (s.queued || 0) + (s.not_delivered || 0) };
    body.innerHTML = '<div class="rv-bar"><select data-ev>' + evs.map(function (e) { return '<option value="' + e.id + '"' + (e.id === state.reportEvent ? ' selected' : '') + '>' + esc(e.title) + '</option>'; }).join('') + '</select>'
      + '<button class="rv-btn" data-kt-iconized="1" data-csv title="Download this view as a CSV file">⬇ CSV</button><span class="rv-hint">Times are ' + esc(rep.tz) + '.</span></div>'
      + '<div class="rv-chips" style="margin-bottom:10px">' + VIEWS.map(function (v) { return '<button data-view="' + v[0] + '" class="' + (v[0] === state.view ? 'on' : '') + '">' + v[1] + (counts[v[0]] != null ? ' · ' + counts[v[0]] : '') + '</button>'; }).join('') + '</div>'
      + (rows.length ? '<div class="kt-card" style="overflow:auto"><table data-kt-export-title="' + esc(exportTitle) + '"><thead><tr><th>Name</th><th>Email</th><th>Invitation</th><th>Sent</th><th title="When the invitation email was first opened (from the image in the email — approximate)">Email opened</th><th title="When they opened the RSVP page from the invitation">Clicked RSVP link</th><th>Signed up</th><th>Opted out</th><th>Reminded</th>'
        + cols.filter(function (c) { return c.key !== 'email'; }).map(function (c) { return '<th>' + esc(c.label) + '</th>'; }).join('') + '</tr></thead><tbody>'
        + rows.map(function (r) {
          return '<tr><td>' + esc(r.name || '—') + '</td><td>' + esc(r.email) + '</td><td>' + sendPill(r.status, r.status_label) + '</td><td>' + esc(r.sent_at || '—') + '</td><td>' + openedCell(r) + '</td><td>' + esc(r.opened_at || '—') + '</td>'
            + '<td>' + esc(r.registered_at || '—') + (r.updated_response_at ? '<div class="rv-hint">updated ' + esc(r.updated_response_at) + '</div>' : '') + '</td><td>' + esc(r.opted_out_at || '—') + '</td><td>' + remindedCell(r) + '</td>'
            + cols.filter(function (c) { return c.key !== 'email'; }).map(function (c) { return '<td style="white-space:pre-line;max-width:320px">' + esc(r.answers[c.key] || '') + '</td>'; }).join('') + '</tr>';
        }).join('') + '</tbody></table></div>'
        : '<div class="kt-card" style="color:#64748B">Nobody in this view.</div>')
      + await optOutsHtml();
    body.querySelector('[data-ev]').onchange = function () { state.reportEvent = +this.value; state.view = 'all'; paint(main); };
    body.querySelector('.rv-chips').onclick = function (e) { var b = e.target.closest('[data-view]'); if (!b) { return; } state.view = b.getAttribute('data-view'); paint(main); };
    body.querySelector('[data-csv]').onclick = function () {
      var ev = evs.filter(function (e) { return e.id === state.reportEvent; })[0] || {};
      download('/admin/rsvp/events/' + state.reportEvent + '/report.csv?view=' + encodeURIComponent(state.view), 'rsvp-' + String(ev.title || 'event').toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-' + state.view + '.csv');
    };
    wireOptOuts(main, body);
  }

  async function optOutsHtml() {
    var d;
    try { d = await Api.get('/admin/rsvp/optouts'); } catch (e) { return ''; }
    var rows = d.optouts || [];
    return '<h3 style="margin:22px 0 4px;font-size:15px">Unsubscribed from all event invitations</h3><div class="rv-hint" style="margin-bottom:8px">Nobody here is sent another invitation by your agency, whichever event it is for.</div>'
      + (rows.length ? '<div class="kt-card" data-optouts><table><thead><tr><th>Email</th><th>When</th><th>From the invitation for</th><th></th></tr></thead><tbody>'
        + rows.map(function (o) {
          return '<tr><td>' + esc(o.email) + '</td><td>' + esc(o.at || '') + '</td><td>' + esc(o.event || '—') + '</td>'
            + '<td style="text-align:center"><button type="button" class="kt-btn" data-resub="' + o.id + '" data-email="' + esc(o.email) + '" title="Resubscribe">Resubscribe</button></td></tr>';
        }).join('') + '</tbody></table></div>' : '<div class="kt-card" style="color:#64748B">Nobody has unsubscribed.</div>');
  }
  function wireOptOuts(main, body) {
    var box = body.querySelector('[data-optouts]'); if (!box) { return; }
    box.onclick = async function (e) {
      var b = e.target.closest('[data-resub]'); if (!b) { return; }
      var ok = await KT.confirm({ title: 'Resubscribe ' + b.getAttribute('data-email') + '?', description: 'Only do this when the person has asked to receive event invitations again.', okLabel: 'Resubscribe' });
      if (!ok) { return; }
      try { await Api.delete('/admin/rsvp/optouts/' + b.getAttribute('data-resub')); toast('Resubscribed', 'success'); paint(main); } catch (err) { toast(err.message || 'Could not update', 'error'); }
    };
  }

  function reg(n) {
    if (!window.KT || !KT.Shell || !KT.Shell.registerScreen) { if ((n || 0) < 100) { setTimeout(function () { reg((n || 0) + 1); }, 100); } return; }
    KT = window.KT;
    ['agency_admin', 'platform_admin'].forEach(function (r) { KT.Shell.registerScreen(r + ':rsvp', render); });
  }
  reg(0);
})(window);
