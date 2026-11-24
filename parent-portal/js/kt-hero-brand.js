/* ═══════════════════════════════════════════════════════════════════
   BRAND BANNER GRADIENT (2026-10-21)
   The colour band at the top of every page, for a white-label brand: start colour,
   optional middle colour, end colour and angle. Unset colours fall back to the brand's
   primary -> accent at 135deg (App\Support\Brand::hero is the server's copy of these
   rules; keep the two in step).

   KT.heroCss(h)            the CSS gradient for {from, via, to, angle}
   KT.heroText(f, v, t)     'light' (white titles) or 'dark' when the colours are too pale
   KT.heroEditor(host, o)   the editor card used by the reseller Brand editor and an
                            agency's own branding screen: colours, angle, a live banner
                            preview and "Reset to default".
     o.value      {hero_from, hero_via, hero_to, hero_angle} as stored ('' = default)
     o.defaults() {from, to}   the brand's current primary/accent
     o.onChange(v) every edit, with the stored-shape values
     o.onSave(v)  optional: shows a "Save banner" button (agency screen)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT = window.KT || {};
  var HEX = /^#[0-9A-Fa-f]{6}$/;

  function lum(h) {
    var c = [1, 3, 5].map(function (i) { var v = parseInt(h.substr(i, 2), 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function midOf(a, b) {
    return '#' + [1, 3, 5].map(function (i) { var v = Math.floor((parseInt(a.substr(i, 2), 16) + parseInt(b.substr(i, 2), 16)) / 2); return (v < 16 ? '0' : '') + v.toString(16); }).join('').toUpperCase();
  }
  KT.heroText = function (from, via, to) {
    if (!HEX.test(from || '') || !HEX.test(to || '')) return 'light';
    var worst = Math.max(lum(from), lum(HEX.test(via || '') ? via : midOf(from, to)));
    var white = 1.05 / (worst + 0.05), dark = (worst + 0.05) / (0.0137 + 0.05);
    return (white >= 3 || white >= dark) ? 'light' : 'dark';
  };
  KT.heroCss = function (h) {
    var a = (h.angle === '' || h.angle == null || isNaN(+h.angle)) ? 135 : Math.max(0, Math.min(360, +h.angle));
    return 'linear-gradient(' + a + 'deg, ' + h.from + ' 0%, ' + (HEX.test(h.via || '') ? h.via + ' 50%, ' : '') + h.to + ' 100%)';
  };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  KT.heroEditor = function (host, o) {
    o = o || {};
    var v = { hero_from: '', hero_via: '', hero_to: '', hero_angle: '' };
    Object.keys(v).forEach(function (k) { if (o.value && o.value[k] != null) v[k] = String(o.value[k]); });
    var defs = function () { var d = (o.defaults && o.defaults()) || {}; return { from: HEX.test(d.from || '') ? d.from : '#1F6080', to: HEX.test(d.to || '') ? d.to : '#13B7CC' }; };
    var eff = function () {
      var d = defs();
      return { from: HEX.test(v.hero_from) ? v.hero_from : d.from, via: HEX.test(v.hero_via) ? v.hero_via : '', to: HEX.test(v.hero_to) ? v.hero_to : d.to,
        angle: v.hero_angle === '' ? 135 : +v.hero_angle };
    };
    var field = function (k, label) {
      return '<div class="kt-hb-f"><span>' + label + '</span><div class="kt-hb-c"><input type="color" data-hc="' + k + '"><input data-hk="' + k + '" maxlength="7" placeholder="default"></div></div>';
    };
    host.innerHTML = '<style>.kt-hb-row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}.kt-hb-f{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:#475569}'
      + '.kt-hb-c{display:flex;gap:6px;align-items:center}.kt-hb-c input[type=color]{width:34px;height:32px;padding:0;border:1px solid #CBD5E1;border-radius:8px;background:#fff;cursor:pointer}'
      + '.kt-hb-c input:not([type]){width:92px;height:32px;padding:0 8px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:13px;box-sizing:border-box}'
      + '.kt-hb-pv{margin-top:14px;border-radius:18px;padding:22px 24px;min-height:96px;display:flex;flex-direction:column;justify-content:center;gap:4px;position:relative;overflow:hidden}'
      + '.kt-hb-pv b{font-size:21px;font-weight:800;line-height:1.2}.kt-hb-pv i{font-style:normal;font-size:13px;opacity:.92}.kt-hb-pv em{position:absolute;right:22px;top:50%;transform:translateY(-50%);font-style:normal;font-size:44px;opacity:.35}</style>'
      + '<h3 style="margin:0 0 4px;font-size:15px;">Page banner</h3><p style="margin:0 0 12px;font-size:12.5px;color:#64748B;">The colour band at the top of every page. Leave a colour blank to use your primary → accent colours.</p>'
      + '<div class="kt-hb-row">' + field('hero_from', 'Start colour') + field('hero_to', 'End colour')
      + '<label class="kt-hb-f" style="flex-direction:row;align-items:center;gap:6px;height:32px;"><input type="checkbox" data-hvia> Middle colour</label>'
      + '<div data-hviaf style="display:none;">' + field('hero_via', 'Middle colour') + '</div>'
      + '<div class="kt-hb-f"><span>Angle <b data-hav></b></span><input type="range" min="0" max="360" step="5" data-ha style="width:150px;"></div></div>'
      + '<div class="kt-hb-pv" data-hpv><b>Good morning, Alex</b><i>Every page banner looks like this.</i><em>🌤️</em></div>'
      + '<div data-htone style="font-size:12px;color:#64748B;margin-top:6px;min-height:16px;"></div>'
      + '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;"><button type="button" class="kt-btn kt-btn-secondary kt-btn-sm" data-kt-iconized="1" data-hreset>Reset to default</button>'
      + (o.onSave ? '<button type="button" class="kt-btn kt-btn-primary kt-btn-sm" data-kt-iconized="1" data-hsave>Save banner</button>' : '') + '<span data-hmsg style="font-size:12.5px;align-self:center;"></span></div>';

    var q = function (s) { return host.querySelector(s); };
    function sync() {
      var e = eff();
      ['hero_from', 'hero_via', 'hero_to'].forEach(function (k) {
        q('[data-hk="' + k + '"]').value = v[k];
        q('[data-hc="' + k + '"]').value = HEX.test(v[k]) ? v[k] : (k === 'hero_from' ? e.from : k === 'hero_to' ? e.to : '#FFFFFF');
      });
      var hasVia = HEX.test(v.hero_via);
      q('[data-hvia]').checked = hasVia || q('[data-hviaf]').style.display !== 'none' && q('[data-hvia]').checked;
      q('[data-hviaf]').style.display = q('[data-hvia]').checked ? '' : 'none';
      q('[data-ha]').value = e.angle; q('[data-hav]').textContent = e.angle + '°';
      paint();
    }
    function paint() {
      var e = eff(), tone = KT.heroText(e.from, e.via, e.to), pv = q('[data-hpv]');
      pv.style.background = KT.heroCss(e);
      pv.style.color = tone === 'dark' ? '#0F172A' : '#FFFFFF';
      q('[data-htone]').textContent = tone === 'dark' ? 'These colours are light, so banner titles switch to dark text to stay readable.' : '';
    }
    function changed() { paint(); if (o.onChange) o.onChange(Object.assign({}, v)); }

    ['hero_from', 'hero_via', 'hero_to'].forEach(function (k) {
      q('[data-hk="' + k + '"]').addEventListener('input', function (ev) {
        var t = ev.target.value.trim(); v[k] = t;
        if (HEX.test(t)) q('[data-hc="' + k + '"]').value = t;
        changed();
      });
      q('[data-hc="' + k + '"]').addEventListener('input', function (ev) { v[k] = ev.target.value.toUpperCase(); q('[data-hk="' + k + '"]').value = v[k]; changed(); });
    });
    q('[data-hvia]').onchange = function (ev) {
      q('[data-hviaf]').style.display = ev.target.checked ? '' : 'none';
      if (!ev.target.checked) { v.hero_via = ''; q('[data-hk="hero_via"]').value = ''; }
      else if (!HEX.test(v.hero_via)) { var e = eff(); v.hero_via = midOf(e.from, e.to); sync(); }
      changed();
    };
    q('[data-ha]').addEventListener('input', function (ev) { v.hero_angle = String(ev.target.value); q('[data-hav]').textContent = ev.target.value + '°'; changed(); });
    q('[data-hreset]').onclick = function () { v = { hero_from: '', hero_via: '', hero_to: '', hero_angle: '' }; q('[data-hvia]').checked = false; sync(); changed(); };
    if (o.onSave) {
      q('[data-hsave]').onclick = async function () {
        var btn = this, msg = q('[data-hmsg]');
        btn.disabled = true; msg.textContent = 'Saving…'; msg.style.color = '#64748B';
        try { await o.onSave(Object.assign({}, v)); msg.textContent = '✓ Saved'; msg.style.color = '#15803D'; }
        catch (e) { msg.textContent = (e && e.message) || 'Could not save.'; msg.style.color = '#B91C1C'; }
        btn.disabled = false;
      };
    }
    sync();
    return { refresh: sync, values: function () { return Object.assign({}, v); } };
  };
})(window);
