/* ============================================================
   KIDDIETRAC v22p9.1 — Sibling-discount tier editor
   Hash: #sibling-discounts — agency_admin only
   Backend: /api/v1/admin/sibling-discounts (v22p9)
   ============================================================ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  function esc(s) { return s == null ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function btn(label, styles, onClick) {
    var b = Dom.el('button', {
      style: 'border:none;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;' + (styles || ''),
    }, label);
    if (onClick) b.addEventListener('click', onClick);
    return b;
  }
  function primary() { return 'background:#1F6080;color:white;'; }
  function ghost()   { return 'background:white;color:#374151;border:1px solid #D1D5DB!important;'; }
  function danger()  { return 'background:transparent;color:#DC2626;border:1px solid #FCA5A5!important;'; }

  function row(rank, percent, onDel) {
    var r = Dom.el('div', {
      class: 'tier-row',
      style: 'display:grid;grid-template-columns:auto 1fr auto 1fr auto;gap:10px;align-items:center;padding:10px 14px;background:white;border-radius:10px;margin-bottom:8px;box-shadow:0 1px 2px rgba(0,0,0,.04);',
    });
    r.appendChild(Dom.el('span', { style: 'font-size:12px;color:#6B7280;font-weight:600;' }, 'Child rank'));
    var rankInput = Dom.el('input', {
      type: 'number', min: '2', max: '20', value: String(rank),
      style: 'width:80px;padding:6px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;',
    });
    r.appendChild(rankInput);
    r.appendChild(Dom.el('span', { style: 'font-size:12px;color:#6B7280;font-weight:600;' }, 'gets'));
    var pctWrap = Dom.el('div', { style: 'display:flex;align-items:center;gap:4px;' });
    var pctInput = Dom.el('input', {
      type: 'number', min: '0', max: '100', step: '0.5', value: String(percent),
      style: 'width:90px;padding:6px 10px;border:1px solid #D1D5DB;border-radius:6px;font-size:14px;',
    });
    pctWrap.appendChild(pctInput);
    pctWrap.appendChild(Dom.el('span', { style: 'font-size:13px;color:#6B7280;' }, '% off'));
    r.appendChild(pctWrap);
    r.appendChild(btn('Remove', danger(), function () { if (onDel) onDel(r); }));
    r._read = function () { return { rank: parseInt(rankInput.value, 10), percent: parseFloat(pctInput.value) }; };
    return r;
  }

  function renderSiblingDiscounts(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1100px;margin:0 auto;' });
    container.appendChild(wrap);

    wrap.appendChild(Dom.el('h1', { style: 'font-size:24px;margin:0 0 6px;' }, '👨‍👩‍👧 Sibling discount tiers'));
    wrap.appendChild(Dom.el('p', {
      style: 'color:#6B7280;font-size:14px;margin:0 0 20px;line-height:1.5;',
    }, 'Apply automatic percentage discounts when a family has multiple children enrolled. The oldest-enrolled child is rank 1 and always pays full price. The 2nd-oldest is rank 2, and so on. Each child gets the highest discount tier whose rank threshold they meet or exceed. Discounts apply only to the monthly tuition line, not subsidies or late fees.'));

    var loading = Dom.el('div', { style: 'padding:40px;text-align:center;color:#6B7280;' }, 'Loading…');
    wrap.appendChild(loading);

    Api.get('/admin/sibling-discounts').then(function (data) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('h1', { style: 'font-size:24px;margin:0 0 6px;' }, '👨‍👩‍👧 Sibling discount tiers'));
      wrap.appendChild(Dom.el('p', {
        style: 'color:#6B7280;font-size:14px;margin:0 0 20px;line-height:1.5;',
      }, 'Apply automatic percentage discounts when a family has multiple children enrolled. The oldest-enrolled child is rank 1 and always pays full price. Each subsequent child gets the highest discount tier whose rank threshold they meet or exceed. Applied to monthly tuition only.'));

      var listWrap = Dom.el('div', { id: 'kt-tier-list' });
      wrap.appendChild(listWrap);

      function rebuild(tiers) {
        Dom.clear(listWrap);
        if (! tiers.length) {
          listWrap.appendChild(Dom.el('div', {
            style: 'padding:20px;background:#F9FAFB;border-radius:10px;color:#6B7280;text-align:center;font-size:14px;margin-bottom:8px;',
          }, 'No tiers configured. Click "Add tier" to start.'));
        }
        tiers.forEach(function (t) {
          listWrap.appendChild(row(t.rank, t.percent, function (el) { el.remove(); }));
        });
      }

      rebuild(data.tiers || []);

      var actions = Dom.el('div', { style: 'display:flex;gap:10px;margin-top:18px;' });
      actions.appendChild(btn('+ Add tier', ghost(), function () {
        // Suggest the next rank
        var rows = listWrap.querySelectorAll('.tier-row');
        var maxRank = 1;
        rows.forEach(function (rowEl) { var v = rowEl._read(); if (v.rank > maxRank) maxRank = v.rank; });
        listWrap.appendChild(row(maxRank + 1, 10, function (el) {
          el.remove();
          // re-render empty state if last row removed
          if (! listWrap.querySelectorAll('.tier-row').length) rebuild([]);
        }));
        // hide empty-state if it exists
        var emptyState = listWrap.querySelector('div:not(.tier-row)');
        if (emptyState) emptyState.remove();
      }));
      actions.appendChild(btn('Save', primary(), function () {
        var tiers = [];
        listWrap.querySelectorAll('.tier-row').forEach(function (rowEl) {
          var v = rowEl._read();
          if (Number.isInteger(v.rank) && v.rank >= 2 && !Number.isNaN(v.percent) && v.percent >= 0 && v.percent <= 100) {
            tiers.push(v);
          }
        });
        Api.patch('/admin/sibling-discounts', { tiers: tiers }).then(function (r) {
          if (Dom.toast) Dom.toast('Saved — applies to next invoice batch', 'success');
          rebuild(r.tiers || []);
        }).catch(function (e) {
          alert('Save failed: ' + (e.message || 'invalid input — ranks must be 2-20 and percents 0-100'));
        });
      }));
      wrap.appendChild(actions);

      wrap.appendChild(Dom.el('p', {
        style: 'color:#6B7280;font-size:12px;margin:24px 0 0;line-height:1.5;',
      }, 'Tip: a common arrangement is rank 2 → 10%, rank 3 → 15%, rank 4 → 20%. Changes take effect on the next invoice generation; previously-generated invoices are not retroactively adjusted.'));
    }).catch(function (e) {
      Dom.clear(wrap);
      wrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + e.message));
    });
  }

  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:sibling-discounts', renderSiblingDiscounts);
  }
  KT.SiblingDiscountsScreen = { render: renderSiblingDiscounts };
})(window);
