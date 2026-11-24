/* kt-child-tagger.js — "Who's in this photo?": tag several children by name (2026-10-20).

   Anthony: "have ability to tag in photos based on childrens name in the room as a feature
   as well which gets logged onto that childrens feed (easy for educator to tag multiple
   kids)".

   One picker, used wherever a photo or video is shared (the care screen's Photo/Video
   tiles, Photos & video → Upload) and to change the tags on a photo already shared
   (Photos & video → 🏷 Tag children). Children are chips with their names; a tap tags or
   untags. Children checked in right now come first with a green dot, and "Everyone here"
   tags the whole room at once. Every tagged child gets the moment on their own timeline,
   and their family sees it: that part is the server's (PhotoFeedController).

   KT.childTagger.load()                     -> Promise<[{id, name, at_centre, group}]>
   KT.childTagger.mount(host, {children, selected, onChange}) -> { value(): [ids] }
   KT.childTagger.edit(photo, onSaved)      -> retag dialog for a shared photo

   PHOTO PERMISSIONS (2026-11-06, App\Support\MediaConsent): each child carries the family's
   answer (consent: none | family | group). A child whose family said "do not photograph"
   cannot be picked (and "Everyone here" skips them); a family-only child is marked, because
   other families will not see a photo they are on. The server refuses either way. */
(function (w, d) {
  'use strict';
  w.KT = w.KT || {};
  if (w.KT.childTagger) { return; }
  var Api = function () { return w.KT.Api; };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function role() {
    try {
      var u = JSON.parse(w.sessionStorage.getItem('kt_user') || w.localStorage.getItem('kt_user') || '{}');
      return u.primary_role || u.role_key || '';
    } catch (e) { return ''; }
  }

  /* The children this person works with: an educator's rooms, else the agency (the same
     rule the care screen uses; the server re-checks every id anyway). */
  var cache = null;
  function load() {
    if (cache) { return Promise.resolve(cache); }
    var r = role();
    function agencyWide() {
      return Api().get('/admin/children').then(function (res) {
        return ((res && res.children) || []).map(function (c) {
          return { id: c.id, name: ((c.first_name || '') + ' ' + (c.last_name || '')).trim(),
                   at_centre: c.is_at_centre === true, group: c.centre_name || '' };
        });
      });
    }
    function rooms() {
      return Api().get('/provider/bootstrap').then(function (boot) {
        var rs = (boot && boot.rooms) || [];
        return Promise.all(rs.map(function (rm) {
          return Api().get('/provider/rooms/' + rm.id + '/roster').then(function (dd) {
            return ((dd && dd.roster) || []).map(function (c) {
              return { id: c.id, name: ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || c.display_name,
                       at_centre: !!c.is_at_centre, group: rm.name };
            });
          }).catch(function () { return []; });
        })).then(function (ls) { return ls.reduce(function (a, b) { return a.concat(b); }, []); });
      });
    }
    var p = (r === 'agency_admin' || r === 'platform_admin') ? agencyWide()
      : rooms().then(function (k) { return k.length ? k : agencyWide(); });
    var levels = Api().get('/admin/photo-consent?levels_only=1').then(function (r) { return (r && r.levels) || {}; }).catch(function () { return {}; });
    return Promise.all([p, levels]).then(function (both) {
      var list = both[0], lv = both[1];
      list.forEach(function (c) { c.consent = lv[c.id] || lv[String(c.id)] || ''; });
      var seen = {};
      list = list.filter(function (c) { if (!c.id || seen[c.id]) { return false; } seen[c.id] = 1; return true; });
      list.sort(function (a, b) {
        if (!!a.at_centre !== !!b.at_centre) { return a.at_centre ? -1 : 1; }
        return String(a.name).localeCompare(String(b.name));
      });
      cache = list;
      setTimeout(function () { cache = null; }, 120000);   // attendance changes through the day
      return list;
    });
  }

  function css() {
    if (d.getElementById('kt-ctag-css')) { return; }
    var st = d.createElement('style'); st.id = 'kt-ctag-css';
    st.textContent = '.kt-ctag{border:1.5px solid #E2E8F0;border-radius:12px;padding:10px;background:#F8FAFC;}'
      + '.kt-ctag-top{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:8px;}'
      + '.kt-ctag-top b{font-size:12.5px;color:#0F172A;margin-right:auto;}'
      /* !important: the portal's global button polish strips borders, padding and
         backgrounds from bare buttons, which flattened every chip into plain text. */
      + '.kt-ctag .kt-ctag-act{border:1px solid #CBD5E1 !important;background:#fff !important;color:#334155 !important;border-radius:999px !important;padding:4px 10px !important;font-size:12px !important;font-weight:700 !important;cursor:pointer;min-height:0 !important;height:auto !important;width:auto !important;}'
      + '.kt-ctag .kt-ctag-find{width:100%;box-sizing:border-box;border:1px solid #CBD5E1;border-radius:9px;padding:7px 10px;font:inherit;font-size:13px;margin-bottom:8px;}'
      + '.kt-ctag-chips{display:flex;flex-wrap:wrap;gap:6px;max-height:190px;overflow:auto;padding:1px;}'
      + '.kt-ctag .kt-ctag-chip{display:inline-flex !important;align-items:center;gap:6px;border:1.5px solid #E2E8F0 !important;background:#fff !important;color:#0F172A !important;border-radius:999px !important;padding:6px 11px !important;font-size:13px !important;font-weight:700 !important;cursor:pointer;line-height:1.2 !important;min-height:0 !important;height:auto !important;width:auto !important;max-width:100%;white-space:nowrap;box-shadow:none !important;}'
      + '.kt-ctag .kt-ctag-chip[aria-pressed="true"]{background:#0E7C90 !important;border-color:#0E7C90 !important;color:#fff !important;}'
      + '.kt-ctag-chip .dot{width:7px;height:7px;border-radius:50%;background:#CBD5E1;flex:0 0 auto;}'
      + '.kt-ctag-chip .dot.here{background:#22C55E;}'
      + '.kt-ctag-chip[aria-pressed="true"] .dot{background:#fff;}'
      + '.kt-ctag .kt-ctag-chip[data-consent="none"]{opacity:.55;cursor:not-allowed;text-decoration:line-through;}'
      + '.kt-ctag-chip .kt-ctag-c{font-size:11px;line-height:1;}'
      + '.kt-ctag-key{font-size:11.5px;color:#64748B;margin-top:7px;}'
      + '.kt-ctag-empty{font-size:12.5px;color:#94A3B8;padding:4px 2px;}';
    d.head.appendChild(st);
  }

  function mount(host, opts) {
    opts = opts || {};
    css();
    var kids = (opts.children || []).slice();
    var sel = {};
    (opts.selected || []).forEach(function (id) { if (id) { sel[String(id)] = true; } });
    var box = d.createElement('div');
    box.className = 'kt-ctag';
    box.setAttribute('data-kt-nodock', '1');
    function taggable(c) { return c.consent !== 'none'; }
    var here = kids.filter(function (c) { return c.at_centre && taggable(c); }).length;
    box.innerHTML = '<div class="kt-ctag-top"><b>🏷 Who’s in this ' + esc(opts.noun || 'photo') + '? <span class="kt-ctag-n" style="color:#0E7C90;"></span></b>'
      + (here ? '<button type="button" class="kt-ctag-act" data-a="here">Everyone here (' + here + ')</button>' : '')
      + '<button type="button" class="kt-ctag-act" data-a="clear">Clear</button></div>'
      + (kids.length > 10 ? '<input type="search" class="kt-ctag-find" data-kt-nofit="1" placeholder="Find a child…">' : '')
      + '<div class="kt-ctag-chips" data-kt-scroll="1"></div>'
      + (kids.some(function (c) { return c.consent === 'none' || c.consent === 'family'; })
        ? '<div class="kt-ctag-key">🚫 family asked: no photos · 👪 family-only photos (other families will not see this ' + esc(opts.noun || 'photo') + ')</div>' : '');
    host.appendChild(box);
    var chips = box.querySelector('.kt-ctag-chips');
    var find = box.querySelector('.kt-ctag-find');
    function count() {
      var n = Object.keys(sel).length;
      box.querySelector('.kt-ctag-n').textContent = n ? '· ' + n + ' tagged' : '';
      if (opts.onChange) { try { opts.onChange(value()); } catch (e) {} }
    }
    function paint() {
      var q = find ? (find.value || '').trim().toLowerCase() : '';
      var list = kids.filter(function (c) { return !q || String(c.name).toLowerCase().indexOf(q) !== -1; });
      chips.innerHTML = list.length ? list.map(function (c) {
        var tip = c.consent === 'none' ? 'Their family asked that they not be photographed'
          : (c.consent === 'family' ? 'Family-only photos: other families will not see a photo they are on' : (c.group || ''));
        return '<button type="button" class="kt-ctag-chip" data-id="' + esc(c.id) + '" data-consent="' + esc(c.consent || '') + '" aria-pressed="' + (sel[String(c.id)] ? 'true' : 'false') + '"'
          + (c.consent === 'none' && !sel[String(c.id)] ? ' aria-disabled="true"' : '')
          + (tip ? ' data-kttip="' + esc(tip) + '"' : '') + '>'
          + '<span class="dot' + (c.at_centre ? ' here' : '') + '"></span>' + esc(c.name)
          + (c.consent === 'none' ? ' <span class="kt-ctag-c">🚫</span>' : (c.consent === 'family' ? ' <span class="kt-ctag-c">👪</span>' : '')) + '</button>';
      }).join('') : '<div class="kt-ctag-empty">' + (kids.length ? 'No one matches.' : 'No children to tag.') + '</div>';
    }
    chips.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('.kt-ctag-chip');
      if (!b) { return; }
      var id = b.getAttribute('data-id');
      // A "no photos" child can be untagged (an old tag) but never tagged.
      if (!sel[id] && b.getAttribute('data-consent') === 'none') {
        if (w.KT.toast) { w.KT.toast('🚫', 'Not this child', 'Their family asked that they not be photographed.', '#B45309'); }
        return;
      }
      if (sel[id]) { delete sel[id]; } else { sel[id] = true; }
      b.setAttribute('aria-pressed', sel[id] ? 'true' : 'false');
      count();
    });
    box.querySelector('.kt-ctag-top').addEventListener('click', function (e) {
      var a = e.target.getAttribute && e.target.getAttribute('data-a');
      if (a === 'here') { kids.forEach(function (c) { if (c.at_centre && taggable(c)) { sel[String(c.id)] = true; } }); }
      else if (a === 'clear') { sel = {}; }
      else { return; }
      paint(); count();
    });
    if (find) { find.addEventListener('input', paint); }
    function value() { return Object.keys(sel).map(Number).filter(Boolean); }
    paint(); count();
    return { value: value, el: box };
  }

  /* Retag a photo already shared. */
  function edit(photo, onSaved) {
    var ov = d.createElement('div');
    ov.className = 'kt-scrim';
    ov.setAttribute('data-no-modal-guard', '1');
    ov.style.cssText = 'position:fixed;inset:0;z-index:2147481000;display:flex;align-items:center;justify-content:center;padding:calc(16px + var(--kt-safe-top, env(safe-area-inset-top, 0px))) 16px calc(16px + var(--kt-safe-bottom, env(safe-area-inset-bottom, 0px)));box-sizing:border-box;';
    var card = d.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:16px;max-width:480px;width:100%;max-height:min(90vh,100%);overflow:auto;padding:18px;box-shadow:0 30px 80px -20px rgba(8,20,40,.6);';
    var isVideo = photo.media_type === 'video';
    card.innerHTML = '<div style="display:flex;gap:12px;align-items:center;margin-bottom:12px;">'
      + (isVideo ? '<div style="width:64px;height:64px;border-radius:10px;background:#0F172A;color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;">🎬</div>'
                 : '<img src="' + esc(photo.thumbnail_url || photo.url) + '" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:10px;">')
      + '<div style="min-width:0;"><div style="font-weight:800;font-size:15.5px;color:#0F172A;">Tag children</div>'
      + '<div style="font-size:12.5px;color:#64748B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(photo.caption || '') + '</div></div></div>'
      + '<div class="kt-ctag-host"><div style="color:#94A3B8;font-size:13px;padding:10px 0;">Loading children…</div></div>'
      + '<div style="font-size:12px;color:#64748B;margin-top:8px;line-height:1.45;">Each child you add gets this on their timeline and their family is told. Removing a child takes it off theirs.</div>'
      + '<div class="kt-ctag-err" style="display:none;margin-top:10px;background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:10px;padding:8px 11px;font-size:12.5px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">'
      + '<button type="button" class="kt-ctag-cancel" style="background:#F1F5F9;color:#334155;border:0;border-radius:10px;padding:9px 16px;font:inherit;font-weight:700;font-size:13px;cursor:pointer;">Cancel</button>'
      + '<button type="button" class="kt-ctag-save" disabled style="background:#0E7C90;color:#fff;border:0;border-radius:10px;padding:9px 18px;font:inherit;font-weight:800;font-size:13px;cursor:pointer;">Save tags</button></div>';
    ov.appendChild(card);
    d.body.appendChild(ov);
    function close() { if (ov.parentNode) { ov.parentNode.removeChild(ov); } }
    card.querySelector('.kt-ctag-cancel').onclick = close;
    ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    var picker = null;
    load().then(function (kids) {
      var host = card.querySelector('.kt-ctag-host');
      host.innerHTML = '';
      // A child tagged earlier who is no longer on this person's list still shows, so it can be untagged.
      var known = {}; kids.forEach(function (k) { known[String(k.id)] = 1; });
      var extra = (photo.child_ids || []).filter(function (id) { return !known[String(id)]; }).map(function (id, i) {
        return { id: id, name: (photo.child_names && photo.child_names[i]) || ('Child #' + id), at_centre: false };
      });
      picker = mount(host, { children: extra.concat(kids), selected: photo.child_ids || [], noun: isVideo ? 'video' : 'photo' });
      card.querySelector('.kt-ctag-save').disabled = false;
    }).catch(function () {
      card.querySelector('.kt-ctag-host').innerHTML = '<div style="color:#B91C1C;font-size:13px;">Could not load the children.</div>';
    });
    card.querySelector('.kt-ctag-save').onclick = function () {
      if (!picker) { return; }
      var btn = this; btn.disabled = true; btn.textContent = 'Saving…';
      Api().patch('/photos/' + photo.id + '/children', { child_ids: picker.value() }).then(function (res) {
        close();
        var bits = [];
        if (res && res.added) { bits.push(res.added + ' added'); }
        if (res && res.removed) { bits.push(res.removed + ' removed'); }
        if (w.KT.toast) { w.KT.toast('🏷', 'Tags saved', (bits.length ? bits.join(', ') + '.' : 'No change.') + (res && res.notice ? ' ' + res.notice : ''), '#16A34A'); }
        if (onSaved) { onSaved(res); }
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Save tags';
        var er = card.querySelector('.kt-ctag-err'); er.textContent = (e && e.message) || 'Could not save the tags.'; er.style.display = '';
      });
    };
  }

  w.KT.childTagger = { load: load, mount: mount, edit: edit };
})(window, document);
