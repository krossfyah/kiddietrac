/* KT ADDRESS AUTOCOMPLETE (2026-10-13, Anthony: "auto complete address when entering new
   family"). Any "Street address" field in the portal gets a suggestion list as you type;
   choosing one fills the street, city, province/state, postal/ZIP code and country fields
   beside it. Suggestions come from /address/suggest (Canada and the US).

   Found by the same sweep idea as kt-phone.js: an input whose name / data-k is
   address_line1, or whose label says "Street address". Its partners are found in the same
   form/card by name, data-k or label, so no form has to be changed to get it. Values are
   written with the native setter and input/change events, so the form's own handlers
   (wizard state, save code) see them as typed. */
(function (w) {
  'use strict';
  var KT = w.KT || (w.KT = {});
  if (KT.Address) return;

  function tok() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } }
  function apiBase() { return (KT_CONFIG_BASE()) || 'https://api.kiddietrac.com/api/v1'; }
  function KT_CONFIG_BASE() { return (w.KT_CONFIG && w.KT_CONFIG.apiBase) || KT.API_BASE || ''; }
  function labelOf(el) {
    var l = el.id && document.querySelector('label[for="' + el.id + '"]');
    if (l) return l.textContent;
    var p = el.parentElement;
    for (var i = 0; p && i < 3; i++, p = p.parentElement) {
      var lab = p.querySelector(':scope > label');
      if (lab) return lab.textContent;
    }
    return '';
  }
  function keyOf(el) {
    return [el.name, el.getAttribute('data-k'), el.id, el.placeholder, el.getAttribute('aria-label'), labelOf(el)].join(' ').toLowerCase();
  }
  function isStreet(el) {
    if (!el || el.tagName !== 'INPUT' || el.dataset.ktAddr || el.type === 'hidden') return false;
    var n = (el.name || el.getAttribute('data-k') || '').toLowerCase();
    if (n === 'address_line1' || n === 'addr') return true;
    var k = keyOf(el);
    return /street address/.test(k) && !/search|filter/.test(k);
  }
  function scopeOf(el) {
    var p = el.parentElement;
    for (var i = 0; p && i < 8; i++, p = p.parentElement) {
      if (p.querySelectorAll('input,select').length >= 3 && find(p, /\bcity\b/) ) return p;
      if (p.tagName === 'FORM') return p;
    }
    return el.form || document.body;
  }
  function find(scope, re, exclude) {
    var list = scope.querySelectorAll('input,select');
    for (var i = 0; i < list.length; i++) {
      var k = keyOf(list[i]);
      if (re.test(k) && !(exclude && exclude.test(k))) return list[i];
    }
    return null;
  }
  var nativeInput = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  var nativeSelect = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
  function put(el, v) {
    if (!el || v == null || v === '') return;
    if (el.tagName === 'SELECT') {
      var opt = [].filter.call(el.options, function (o) { return o.value === v || o.textContent.trim() === v; })[0];
      if (!opt) return;
      nativeSelect.set.call(el, opt.value);
    } else {
      nativeInput.set.call(el, v);
    }
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function css() {
    if (document.getElementById('kt-addr-css')) return;
    var s = document.createElement('style');
    s.id = 'kt-addr-css';
    s.textContent = '.kt-addr-list{position:fixed;z-index:2147483000;background:#fff;border:1px solid #E2E8F0;border-radius:10px;'
      + 'box-shadow:0 14px 34px -12px rgba(15,23,42,.35);overflow:hidden;font-family:inherit;max-height:280px;overflow-y:auto;}'
      + '.kt-addr-item{display:block;width:100%;text-align:left;border:0;background:#fff;padding:9px 12px;font-size:13.5px;color:#0F172A;cursor:pointer;font-family:inherit;line-height:1.35;}'
      + '.kt-addr-item small{display:block;color:#64748B;font-size:12px;}'
      + '.kt-addr-item.on,.kt-addr-item:hover{background:#EFF8FA;}'
      + '.kt-addr-foot{font-size:10.5px;color:#94A3B8;padding:4px 12px 6px;border-top:1px solid #F1F5F9;}';
    document.head.appendChild(s);
  }

  function attach(el) {
    el.dataset.ktAddr = '1';
    el.setAttribute('autocomplete', 'off');
    var list = null, items = [], cur = -1, timer = null, lastQ = '', seq = 0;
    function close() { if (list) { list.remove(); list = null; } items = []; cur = -1; }
    function place() {
      if (!list) return;
      var r = el.getBoundingClientRect();
      list.style.left = r.left + 'px'; list.style.top = (r.bottom + 4) + 'px'; list.style.width = Math.max(r.width, 280) + 'px';
    }
    function pick(s) {
      var scope = scopeOf(el);
      var country = find(scope, /country/);
      var prov = find(scope, /province|\bstate\b/, /country/);
      var city = find(scope, /\bcity\b/);
      var postal = find(scope, /postal|\bzip\b/);
      put(country, s.country);
      // A country select may rebuild the province list (onboarding): fill after it settles.
      put(el, s.address_line1);
      put(city, s.city);
      put(postal, s.postal_code);
      setTimeout(function () { put(prov || find(scope, /province|\bstate\b/, /country/), s.province); }, 30);
      close();
    }
    function render(sugs) {
      close();
      if (!sugs.length || document.activeElement !== el) return;
      css();
      list = document.createElement('div');
      list.className = 'kt-addr-list';
      list.setAttribute('role', 'listbox');
      sugs.forEach(function (s, i) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'kt-addr-item'; b.setAttribute('role', 'option');
        b.textContent = s.address_line1;
        var sm = document.createElement('small');
        sm.textContent = [s.city, s.province, s.postal_code].filter(Boolean).join(', ') + (s.country === 'US' ? ' · USA' : '');
        b.appendChild(sm);
        b.addEventListener('mousedown', function (e) { e.preventDefault(); pick(s); });
        list.appendChild(b); items.push(b);
      });
      var foot = document.createElement('div'); foot.className = 'kt-addr-foot'; foot.textContent = 'Address data © OpenStreetMap contributors';
      list.appendChild(foot);
      document.body.appendChild(list);
      place();
      list._sugs = sugs;
    }
    function look() {
      var q = el.value.trim();
      if (q.length < 4 || q === lastQ) return;
      lastQ = q;
      var scope = scopeOf(el), c = find(scope, /country/);
      var cc = c && /US|United States/i.test(c.value || '') ? 'US' : 'CA';
      var t = tok(); if (!t) return;
      var my = ++seq;
      fetch(apiBase() + '/address/suggest?q=' + encodeURIComponent(q) + '&country=' + cc, { headers: { 'Authorization': 'Bearer ' + t, 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { suggestions: [] }; })
        .then(function (d) { if (my === seq) render((d && d.suggestions) || []); })
        .catch(function () {});
    }
    el.addEventListener('input', function (e) { if (e.isTrusted === false) return; clearTimeout(timer); timer = setTimeout(look, 400); });
    el.addEventListener('keydown', function (e) {
      if (!list) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        cur = (cur + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach(function (b, i) { b.classList.toggle('on', i === cur); });
      } else if (e.key === 'Enter' && cur >= 0) { e.preventDefault(); pick(list._sugs[cur]); }
      else if (e.key === 'Escape') { close(); }
    });
    el.addEventListener('blur', function () { setTimeout(close, 150); });
    w.addEventListener('scroll', place, true);
    w.addEventListener('resize', place);
  }

  function sweep() {
    var roots = [document.getElementById('appMain'), document.getElementById('modalRoot')];
    if (!roots[0] && !roots[1]) roots = [document.body];
    roots.forEach(function (r) {
      if (!r) return;
      [].forEach.call(r.querySelectorAll('input:not([data-kt-addr])'), function (el) { try { if (isStreet(el)) attach(el); } catch (e) {} });
    });
  }
  KT.Address = { attach: attach, sweep: sweep };
  if (KT.sweepBus) KT.sweepBus.on(sweep); else setInterval(sweep, 3000);
  w.addEventListener('hashchange', function () { setTimeout(sweep, 200); setTimeout(sweep, 700); });
  (function watchModals() {
    var mr = document.getElementById('modalRoot');
    if (!mr) { if (document.getElementById('appMain') || document.readyState === 'loading') setTimeout(watchModals, 300); return; }
    if (!w.MutationObserver) return;
    var pending = false;
    var obs = new MutationObserver(function () {
      if (pending) return; pending = true;
      setTimeout(function () { pending = false; try { sweep(); } catch (e) {} }, 60);
    });
    obs.observe(mr, { childList: true, subtree: true });
  })();
  setTimeout(sweep, 500);
})(window);
