/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v14 — Lesson plans (weekly grid)
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';

  function token() { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); }
  function apiBase() { return (window.KT && window.KT.API_BASE) || 'https://api.kiddietrac.com/api/v1'; }
  function getUser() { try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}'); } catch (e) { return {}; } }
  function getRole() { const u = getUser(); return u.primary_role || (u.roles && u.roles[0]) || 'guest'; }

  async function api(method, path, body) {
    const opts = { method, headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const res = await fetch(apiBase() + path, opts);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }

  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return (r || document).querySelectorAll(s); }

  // ── pictures ──────────────────────────────────────────────────────────────
  function scopeParams() { return activeScope === 'centre' ? { centre_id: activeCentreId } : { room_id: activeRoomId }; }
  function picUrl(a) { return (a && (a.thumb_url || a.image_url || a.image)) || ''; }
  function fullUrl(a) { return (a && (a.image_url || a.image || a.thumb_url)) || ''; }
  function wantsPic(a) { return !!(a && (a.title || '').trim() && !picUrl(a) && a.image_source !== 'none'); }
  /** A lazy-loaded picture that opens full size on tap (KT.mediaViewer, which pinch-zooms) - read-only views only. */
  function picImg(a, cls, title) {
    const u = picUrl(a);
    if (!u) return '';
    const credit = a.image_credit ? 'Picture: ' + a.image_credit : '';
    return '<img class="' + cls + ' lp-zoom" src="' + esc(u) + '" data-full="' + esc(fullUrl(a)) + '" data-cap="' + esc((title || '') + (credit ? ' \u00b7 ' + credit : '')) + '"'
      + ' alt="' + esc(title || '') + '" loading="lazy" decoding="async"' + (credit ? ' title="' + esc(credit) + '"' : '') + ' onerror="this.remove()">';
  }
  async function apiForm(path, fd) {
    const res = await fetch(apiBase() + path, { method: 'POST', headers: { 'Authorization': 'Bearer ' + token(), 'Accept': 'application/json' }, body: fd });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || ('API ' + res.status));
    return json;
  }
  if (!window.__ktLpZoom) {
    window.__ktLpZoom = 1;
    document.addEventListener('click', function (e) {
      const im = e.target && e.target.closest && e.target.closest('img.lp-zoom');
      if (!im || im.closest('.kt-activity-tap')) return;   // while editing, a tap opens the activity instead
      e.preventDefault(); e.stopPropagation();
      if (window.KT && KT.mediaViewer && KT.mediaViewer.open) {
        KT.mediaViewer.open([{ url: im.getAttribute('data-full') || im.src, type: 'image', caption: im.getAttribute('data-cap') || '' }], 0);
      }
    }, true);
  }

  /** The Picture block inside a dialog: preview, Choose / Upload my own / Find with AI / Remove. */
  function picSection(label) {
    return '<label class="lp-ph-lbl">' + esc(label || 'Picture') + '</label>'
      + '<div class="lp-ph"><div class="lp-ph-prev" data-ph="prev"></div><div class="lp-ph-btns">'
      + '<button type="button" data-ph="pick">\ud83d\uddbc Choose</button>'
      + '<button type="button" data-ph="up">\u2b06 Upload my own</button>'
      + (lpImages.ai ? '<button type="button" data-ph="ai">\u2728 Find with AI</button>' : '')
      + '<button type="button" data-ph="rm" class="lp-ph-rm">Remove</button>'
      + '</div></div>'
      + '<div class="lp-ph-note" data-ph="note"></div>'
      + '<div class="lp-ph-box" data-ph="box" hidden><div class="lp-ph-search"><input data-ph="q" placeholder="Search pictures, e.g. leaves" data-kt-nospell="1"><button type="button" data-ph="go">Search</button></div>'
      + '<div class="lp-ph-grid" data-ph="grid"></div><div class="lp-ph-src">Openly licensed pictures from Wikimedia Commons (CC0, public domain or CC BY, credited).</div></div>'
      + '<input type="file" data-ph="file" accept="image/*" hidden>';
  }
  function wirePic(root, start, ctx) {
    const st = {};
    PIC_FIELDS.forEach(f => { st[f] = start && start[f] != null ? start[f] : null; });
    if (start && !st.image_url && start.image) { st.image_url = start.image; st.thumb_url = start.image; st.image_source = 'library'; }
    const q = (k) => root.querySelector('[data-ph="' + k + '"]');
    const note = (t, c) => { const n = q('note'); n.textContent = t || ''; n.style.color = c || '#64748B'; };
    function paint() {
      const u = st.thumb_url || st.image_url;
      q('prev').innerHTML = u ? '<img src="' + esc(u) + '" alt="">' : '<span>No picture</span>';
      q('rm').hidden = !u;
      if (u) note(st.image_credit ? 'Picture: ' + st.image_credit : (st.image_source === 'upload' ? 'Your own photo' : ''));
      else if (st.image_source === 'none') note('Removed \u2014 no picture will be added automatically.');
      else note(lpImages.auto ? 'A picture is added automatically after you save, when a good one is found.' : 'No picture yet.');
    }
    const set = (img) => { PIC_FIELDS.forEach(f => { st[f] = img && img[f] != null ? img[f] : null; }); paint(); };
    const busy = (b, on) => { if (!b) return; b.disabled = on; b.setAttribute('aria-busy', on ? 'true' : 'false'); };
    async function search(term) {
      const box = q('box'), grid = q('grid');
      box.hidden = false;
      grid.innerHTML = '<div class="lp-ph-msg">Searching\u2026</div>';
      const c = ctx();
      if (!term && !c.title) { grid.innerHTML = '<div class="lp-ph-msg">Type the activity first, or search for a picture above.</div>'; return; }
      try {
        const r = await api('POST', '/provider/lesson-plans/image/search', Object.assign({ title: c.title, notes: c.notes, theme: c.theme, q: term || '' }, scopeParams()));
        if (!term && r.terms && r.terms[0] && !q('q').value) q('q').value = r.terms[0];
        const list = r.candidates || [];
        grid.innerHTML = list.length
          ? list.map((x, i) => '<button type="button" class="lp-ph-cand" data-i="' + i + '" title="' + esc(x.title + ' \u00b7 ' + x.credit) + '"><img src="' + esc(x.thumb) + '" alt="' + esc(x.title) + '" loading="lazy" onerror="this.parentNode.remove()"></button>').join('')
          : '<div class="lp-ph-msg">No suitable openly licensed pictures found. Try another word, or upload your own.</div>';
        grid.querySelectorAll('.lp-ph-cand').forEach(b => {
          b.onclick = async () => {
            const x = list[+b.getAttribute('data-i')];
            busy(b, true); note('Adding the picture\u2026');
            try { const p2 = await api('POST', '/provider/lesson-plans/image/pick', Object.assign({ key: x.key }, scopeParams())); set(p2.image); box.hidden = true; }
            catch (e) { note(e.message, '#B91C1C'); }
            finally { busy(b, false); }
          };
        });
      } catch (e) { grid.innerHTML = '<div class="lp-ph-msg" style="color:#B91C1C;">' + esc(e.message) + '</div>'; }
    }
    q('pick').onclick = () => { const box = q('box'); if (!box.hidden) { box.hidden = true; return; } search(''); };
    q('go').onclick = () => search(q('q').value.trim());
    q('q').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); search(q('q').value.trim()); } });
    q('up').onclick = () => q('file').click();
    q('file').onchange = async () => {
      const f = q('file').files && q('file').files[0];
      if (!f) return;
      const fd = new FormData(); fd.append('photo', f);
      const sp = scopeParams(); Object.keys(sp).forEach(k => fd.append(k, sp[k]));
      busy(q('up'), true); note('Uploading\u2026');
      try { const r = await apiForm('/provider/lesson-plans/image/upload', fd); set(r.image); }
      catch (e) { note(e.message, '#B91C1C'); }
      finally { busy(q('up'), false); q('file').value = ''; }
    };
    if (q('ai')) {
      q('ai').onclick = async () => {
        const c = ctx();
        if (!c.title) { note('Type the activity first.', '#92400E'); return; }
        busy(q('ai'), true); note('\u2728 Looking for a picture\u2026');
        try {
          const r = await api('POST', '/ai/assist/lesson-image', Object.assign({ title: c.title, notes: c.notes, theme: c.theme }, scopeParams()));
          if (r.image) set(r.image); else note('No picture fits this one honestly. Choose one or upload your own.', '#92400E');
        } catch (e) { note(e.message, '#B91C1C'); }
        finally { busy(q('ai'), false); }
      };
    }
    q('rm').onclick = () => { set(null); st.image_source = 'none'; paint(); };
    paint();
    return st;
  }
  function picFields(st) { const o = {}; PIC_FIELDS.forEach(f => { o[f] = st[f] != null ? st[f] : null; }); return o; }

  /** Ask the server to find the pictures a SAVED week is missing, then show them (only if still reading that week). */
  async function fillPictures(container, force) {
    const key = draftKey();
    const say = (t, c) => { const el = document.getElementById('kt-save-status'); if (el && t) { el.style.color = c || '#6B7280'; el.textContent = t; } };
    say('\ud83d\uddbc Finding pictures\u2026');
    let filled = 0, left = 0;
    for (let round = 0; round < 4; round++) {
      let r;
      try { r = await api('POST', '/provider/lesson-plans/fill-images', Object.assign({ week_starting: activeWeek, force: !!force }, scopeParams())); }
      catch (e) { say('Pictures: ' + e.message, '#B91C1C'); return; }
      filled += r.filled || 0; left = r.left || 0;
      if (r.done || !r.filled) break;
    }
    if (draftKey() !== key || !lpReadOnly) return;   // moved to another week, or editing: never clobber
    await renderProvider(container);
    say(filled
      ? '\ud83d\uddbc Added ' + filled + ' picture' + (filled === 1 ? '' : 's') + (left ? ' \u00b7 ' + left + ' without a good match (choose or upload one)' : '')
      : (left ? 'No suitable picture found for ' + left + (left === 1 ? ' activity' : ' activities') + ' \u2014 choose or upload one.' : 'Every activity has a picture.'),
      filled ? '#047857' : '#92400E');
  }

  const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

  /* Opens read-only. Editing is a deliberate act, not the default state of a
     screen people mostly come to in order to READ. */
  var lpReadOnly = true;
  var lpStatus = 'published';

  /** Weekday name in the AGENCY's timezone — never the device's. A tablet left on
     UTC must not tint Tuesday's column on a Monday evening. */
  function agencyWeekdayKey() {
    try {
      var t = (window.KT && KT.agencyToday) ? KT.agencyToday()
        : new Date().toISOString().slice(0, 10);
      var names = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
      return names[new Date(t + 'T12:00:00').getDay()] || null;
    } catch (e) { return null; }
  }

  /** Is the week on screen the one we are actually in? */
  function viewingCurrentWeek() {
    try {
      var t = (window.KT && KT.agencyToday) ? KT.agencyToday()
        : new Date().toISOString().slice(0, 10);
      return String(activeWeek) === String(mondayOf(new Date(t + 'T12:00:00')));
    } catch (e) { return false; }
  }
  const DAY_LABELS = { monday: 'Mon', tuesday: 'Tue', wednesday: 'Wed', thursday: 'Thu', friday: 'Fri' };
  const DOMAINS = [
    { v: 'social_emotional',  l: '💛 Social/Emotional',   c: '#FCD34D' },
    { v: 'physical',          l: '🏃 Physical',           c: '#FB923C' },
    { v: 'language_literacy', l: '📚 Language/Literacy',  c: '#60A5FA' },
    { v: 'cognitive',         l: '🧠 Cognitive',          c: '#A78BFA' },
    { v: 'creative_arts',     l: '🎨 Creative Arts',      c: '#F472B6' },
    { v: 'self_care',         l: '🧼 Self Care',          c: '#34D399' },
    { v: 'outdoor',           l: '🌳 Outdoor',            c: '#86EFAC' },
  ];

  /* HDLH foundations (2026-11-03): activities from the Ontario curriculum library carry them, and the
     Framework gaps report counts planned activities by them. Editable in the activity dialog. */
  const FND = {
    belonging:  { l: 'Belonging',  i: '🤝', c: '#C2410C', b: '#FFF1E6' },
    wellbeing:  { l: 'Well-being', i: '🌱', c: '#047857', b: '#E7F7EF' },
    engagement: { l: 'Engagement', i: '🔍', c: '#1D4ED8', b: '#E8EFFE' },
    expression: { l: 'Expression', i: '🎨', c: '#7E22CE', b: '#F4EAFD' },
  };
  function fndChips(a) {
    return ((a && a.foundations) || []).filter(k => FND[k]).map(k =>
      `<span title="HDLH: ${FND[k].l}" style="display:inline-flex;align-items:center;gap:2px;padding:0 6px;border-radius:20px;background:${FND[k].b};color:${FND[k].c};font-size:10.5px;font-weight:700;line-height:18px;white-space:nowrap;">${FND[k].i} ${FND[k].l}</span>`).join(' ');
  }
  /* The curriculum unit this week came from: what to watch for, and ideas to share with families. */
  function unitBanner() {
    const u = currentPlan && currentPlan.unit;
    if (!u || !u.title) return '';
    const li = (arr) => (arr || []).map(x => `<li style="margin:0 0 4px;">${esc(x)}</li>`).join('');
    return `<details class="lp-note" style="display:block;">
      <summary style="cursor:pointer;">📖 <strong>From the curriculum unit “${esc(u.title)}”</strong>${u.of > 1 ? ` · week ${u.week} of ${u.of}` : ''}${u.focus ? ` — ${esc(u.focus)}` : ''}
        <span style="color:#1F6080;font-weight:700;white-space:nowrap;">· observation prompts and family ideas</span></summary>
      ${(u.observe || []).length ? `<div style="font-weight:800;font-size:12px;margin:8px 0 3px;">👀 Watch for</div><ul style="margin:0;padding-left:18px;font-size:12.5px;">${li(u.observe)}</ul>` : ''}
      ${(u.family || []).length ? `<div style="font-weight:800;font-size:12px;margin:8px 0 3px;">🏡 Family ideas (families see these once the week is published)</div><ul style="margin:0;padding-left:18px;font-size:12.5px;">${li(u.family)}</ul>` : ''}
      ${(u.french || []).length ? `<div style="font-weight:800;font-size:12px;margin:8px 0 3px;">🇫🇷 French words</div><div style="font-size:12.5px;">${u.french.map(p => `${esc(p[0])} → <strong lang="fr">${esc(p[1])}</strong>`).join(' · ')}</div>` : ''}
      ${u.id ? `<div style="margin-top:8px;"><a href="#curriculum?unit=${+u.id}" style="color:#1F6080;font-weight:700;font-size:12.5px;">Open the unit in the curriculum library →</a></div>` : ''}
    </details>`;
  }

  // Each weekday gets its own accent so the week reads at a glance.
  const DAY_COLORS = {
    monday:    '#2563EB',
    tuesday:   '#0D9488',
    wednesday: '#7C3AED',
    thursday:  '#EA580C',
    friday:    '#16A34A',
  };
  // Fallback palette so an activity with NO HDLH domain (e.g. one added from a
  // curriculum template) still gets a distinct colour instead of a wall of grey.
  const ACTIVITY_PALETTE = ['#60A5FA', '#34D399', '#F472B6', '#FBBF24', '#A78BFA', '#22D3EE', '#FB923C', '#4ADE80'];

  function mondayOf(date) {
    // Timezone-safe. `new Date("YYYY-MM-DD")` parses as UTC midnight, and
    // `.toISOString()` formats back in UTC — so for anyone behind UTC (Eastern)
    // this drifted the Monday into the PREVIOUS week. That mismatch is why a plan
    // saved to week X (via screen-v22p57's local-based _mondayISO) opened the
    // planner on week X-1 with an empty grid — the "Use template does nothing" bug.
    // Parse a date-only string as LOCAL midnight and format with local parts so it
    // agrees with _mondayISO exactly.
    let d;
    if (date instanceof Date) {
      d = new Date(date.getTime());
    } else if (/^\d{4}-\d{2}-\d{2}$/.test(String(date))) {
      const p = String(date).split('-');
      d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    } else {
      d = new Date(date);
    }

    /* AN UNPARSEABLE DATE MUST NOT BECOME THE WEEK. (2026-09-21)

       `<input type="date">` hands back '' the moment it is cleared, and new Date('') is
       an Invalid Date whose getDay() is NaN. Every line below then propagated it and this
       returned the literal string 'NaN-NaN-NaN'.

       That string became activeWeek, and activeWeek is sticky: the loader asked the
       server for week NaN-NaN-NaN (empty grid), the pager could not step off it because
       new Date('NaN-NaN-NaN' + 'T12:00:00') is Invalid too, and every save posted it and
       came back 422. The planner was wedged until the page was reloaded.

       Amna Ahsan hit exactly this on 2026-09-21 at 14:25 — a published save rejected with
       week_starting 'NaN-NaN-NaN' and an empty plan — and only got her lesson plan in at
       23:12, after a reload. Falling back to the current week keeps the screen usable:
       clearing the field now just returns you to this week. */
    if (! date || ! d || isNaN(d.getTime())) {
      /* `! date` as well as the NaN check: new Date(null) is epoch 0, which is a VALID
         date, so a null slipped past and planted the week of 29 Dec 1969. */
      d = new Date();
    }

    const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + diff);
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + mm + '-' + dd;
  }

  let activeRoomId = null;
  let activeCentreId = null;
  let activeScope = 'room';        // v22p84: 'room' or 'centre'
  let lastCentres = [];            // v22p84: agency centres (so centre-wide plans work even with 0 rooms)
  let activeWeek = mondayOf(new Date());
  let currentPlan = null;
  let currentTheme = '';
  /* An unsaved week filled from a template or the AI (2026-09-29). renderProvider reloads
     the saved plan, which silently threw these away -- the AI dialog said "Draft ready"
     and the week came back unchanged. Kept per room/centre + week until Save or Discard. */
  let pendingDraft = null;   // { key, theme, days, source }
  let lpDay = null;          // the day shown on a phone (2026-10-23)
  /* PICTURES (2026-11-02, App\Support\LessonImages). Each activity, and the week's theme ("cover"), can carry a
     picture: image_url, thumb_url, image_source (library | upload | auto | none), lesson_image_id. 'none' = removed on
     purpose and never refilled. The server keeps a saved picture when a save sends none (templates, the AI draft). */
  let lpImages = { auto: true, ai: false, can_manage: false };
  let currentCover = null;
  const PIC_FIELDS = ['image_url', 'thumb_url', 'image_source', 'lesson_image_id', 'image_credit', 'image_credit_url', 'image_credit_required'];
  function draftKey() {
    return activeScope + ':' + (activeScope === 'centre' ? activeCentreId : activeRoomId) + ':' + activeWeek;
  }

  async function getRooms() {
    const me = getUser();
    const role = (me && (me.primary_role || (me.roles && me.roles[0]))) || '';
    // v21.1: only agency_admin can hit /admin/centres; everyone else uses /provider/bootstrap.
    if (role === 'agency_admin') {
      try {
        const res = await api('GET', '/admin/centres');
        lastCentres = (res.centres || []).map(c => ({ id: c.id, name: c.name }));
        const rooms = [];
        (res.centres || []).forEach(c => {
          (c.rooms || []).forEach(r => rooms.push({ ...r, centre_name: c.name, centre_id: c.id }));
        });
        // Return even when empty — centre-wide plans still work via lastCentres.
        return rooms;
      } catch (e) {}
    }
    try {
      const res = await api('GET', '/provider/bootstrap');
      return res.rooms || [];
    } catch (e) {
      return [];
    }
  }

  /* ── Draft with AI ─────────────────────────────────────────────────────
     The generator speaks HDLH foundations (belonging / wellbeing / engagement /
     expression); the planner stores learning domains. They are different taxonomies, so
     this is a deliberate approximation rather than a lookup — an educator can change any
     of it before saving, which is the point of dropping it into the grid rather than
     saving it for them. */
  var HDLH_TO_DOMAIN = {
    belonging: 'social_emotional',
    wellbeing: 'self_care',
    engagement: 'cognitive',
    expression: 'creative_arts',
  };
  var AI_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

  function aiPlanToDays(plan) {
    var out = { monday: [], tuesday: [], wednesday: [], thursday: [], friday: [] };
    var days = (plan && plan.days) || [];
    days.forEach(function (d, i) {
      // Prefer the day's own name; fall back to position, since a generator that returns
      // five days in order but mislabels one should not lose a day.
      var key = String(d.day || '').toLowerCase().trim();
      if (AI_DAYS.indexOf(key) === -1) { key = AI_DAYS[i] || null; }
      if (!key) { return; }
      (d.activities || []).forEach(function (a) {
        var notes = String(a.description || '');
        if (Array.isArray(a.materials) && a.materials.length) {
          notes += (notes ? ' ' : '') + 'Materials: ' + a.materials.join(', ') + '.';
        }
        out[key].push({
          time: '',                       // the educator places it in the day
          title: String(a.title || '').trim(),
          domain: HDLH_TO_DOMAIN[String(a.hdlh_foundation || '').toLowerCase()] || null,
          notes: notes.trim(),
        });
      });
    });
    return out;
  }

  function openAiDialog(container) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:12000;display:flex;align-items:center;justify-content:center;padding:16px;';
    overlay.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:440px;width:100%;padding:20px 22px;box-shadow:0 18px 48px rgba(0,0,0,.28);">'
      + '<div style="font-size:16px;font-weight:800;color:#0D1B2A;margin:0 0 4px;">✨ Draft this week with AI</div>'
      + '<div style="font-size:12.5px;color:#64748B;margin:0 0 14px;">Five days of activities land in the grid for you to edit. Nothing is saved until you press save.</div>'
      + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Theme</label>'
      + '<input id="kt-ai-theme" type="text" placeholder="e.g. All about me" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;margin-bottom:12px;">'
      + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Age group</label>'
      + '<select id="kt-ai-age" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;background:#fff;margin-bottom:12px;">'
      + '<option value="infant">Infant</option><option value="toddler" selected>Toddler</option>'
      + '<option value="preschool">Preschool</option><option value="school-age">School age</option></select>'
      + '<label style="display:block;font-size:13px;font-weight:700;color:#475569;margin:0 0 4px;">Anything to keep in mind (optional)</label>'
      + '<textarea id="kt-ai-notes" rows="2" placeholder="e.g. Several children are new — emphasise belonging." style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #D6DEE7;border-radius:9px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>'
      + '<div id="kt-ai-msg" style="font-size:12.5px;color:#64748B;min-height:18px;margin:8px 0 0;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;">'
      + '<button id="kt-ai-cancel" style="background:#fff;color:#374151;border:1px solid #D1D5DB;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>'
      + '<button id="kt-ai-go" style="background:#1F6080;color:#fff;border:0;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">Generate</button>'
      + '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { overlay.remove(); } });
    overlay.querySelector('#kt-ai-cancel').addEventListener('click', function () { overlay.remove(); });

    var themeEl = overlay.querySelector('#kt-ai-theme');
    themeEl.value = (currentPlan && currentPlan.theme) || '';
    setTimeout(function () { themeEl.focus(); }, 40);

    overlay.querySelector('#kt-ai-go').addEventListener('click', async function () {
      var theme = themeEl.value.trim();
      var msg = overlay.querySelector('#kt-ai-msg');
      var go = overlay.querySelector('#kt-ai-go');
      if (theme.length < 3) { msg.innerHTML = '<span style="color:#DC2626;">Give it a theme to work from.</span>'; return; }
      go.disabled = true; go.textContent = 'Drafting…';
      msg.textContent = 'Drafting five days — usually 10–20 seconds.';
      try {
        var res = await api('POST', '/director/lesson-plans-ai/generate', {
          centre_id: parseInt(activeCentreId, 10),
          age_group: overlay.querySelector('#kt-ai-age').value,
          theme: theme,
          week_starting: activeWeek,
          starter_notes: overlay.querySelector('#kt-ai-notes').value.trim() || null,
        });
        var days = aiPlanToDays(res && res.plan);
        pendingDraft = { key: draftKey(), theme: theme, days: days, source: 'the AI draft' };
        lpReadOnly = false;
        overlay.remove();
        renderProvider(container);
        if (window.KT && KT.toast) { KT.toast('✨', 'Draft ready', 'Edit anything, then save.'); }
      } catch (e) {
        var detail = (e && e.message) || 'Server error';
        msg.innerHTML = '<span style="color:#DC2626;">Could not draft: ' + esc(detail) + '</span>';
        go.disabled = false; go.textContent = 'Generate';
      }
    });
  }

  async function renderProvider(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading lesson planner…</div>';
    const rooms = await getRooms();

    // v22p84: a plan can target a room OR a whole centre. Prefer the full agency
    // centre list (works even when no rooms exist yet); otherwise derive from rooms.
    let centres = [];
    if (lastCentres && lastCentres.length) {
      centres = lastCentres.slice();
    } else {
      const seenCentre = {};
      rooms.forEach(r => {
        if (r.centre_id != null && !seenCentre[r.centre_id]) {
          seenCentre[r.centre_id] = 1;
          centres.push({ id: r.centre_id, name: r.centre_name || ('Centre ' + r.centre_id) });
        }
      });
    }

    const hasRooms = rooms.length > 0;
    const canCentre = centres.length > 0;
    if (!hasRooms && !canCentre) {
      container.innerHTML = '<div style="padding:32px;color:#DC2626;">No rooms or centres found. Add a centre first.</div>';
      return;
    }
    // Pick a valid scope for what's available.
    if (!hasRooms) activeScope = 'centre';
    if (activeScope === 'centre' && !canCentre) activeScope = 'room';
    if (activeScope === 'room' && !hasRooms) activeScope = 'centre';

    if (hasRooms && activeRoomId && !rooms.find(r => r.id == activeRoomId)) activeRoomId = null;
    if (hasRooms && !activeRoomId && rooms.length === 1) activeRoomId = rooms[0].id;
    // Select a room first (2026-10-24) — never the first one in the list.
    if (activeScope === 'room' && hasRooms && !activeRoomId && window.KT && KT.selectFirst) {
      KT.selectFirst(container, { word: 'Room', items: rooms.map(r => ({ id: r.id, name: (r.name || ('Room ' + r.id)) + (r.centre_name && centres.length > 1 ? ' — ' + r.centre_name : '') })),
        onPick: function (id) { activeRoomId = id; renderProvider(container); } });
      return;
    }
    if (!activeCentreId || !centres.find(c => c.id == activeCentreId)) {
      var _ar = rooms.find(r => r.id == activeRoomId);
      activeCentreId = (_ar && _ar.centre_id) || (centres[0] && centres[0].id) || null;
    }

    // Scope toggle options — only offer what exists.
    var scopeOpts = '';
    if (hasRooms)  scopeOpts += '<option value="room" '   + (activeScope==='room'?'selected':'')   + '>🚪 This room</option>';
    if (canCentre) scopeOpts += '<option value="centre" ' + (activeScope==='centre'?'selected':'') + '>🏫 Whole centre</option>';
    var showScopeToggle = hasRooms && canCentre;

    let plan;
    const loadQs = (activeScope === 'centre') ? ('centre_id=' + activeCentreId) : ('room_id=' + activeRoomId);
    try { plan = await api('GET', '/provider/lesson-plans?' + loadQs + '&week_starting=' + activeWeek); }
    catch (e) { container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>'; return; }

    currentPlan = plan.plan;
    currentTheme = plan.theme || '';
    lpImages = plan.images || lpImages;
    currentCover = plan.cover || null;
    var lpDraft = pendingDraft && pendingDraft.key === draftKey() ? pendingDraft : null;
    if (pendingDraft && !lpDraft) { pendingDraft = null; }   // moved to another week or room
    if (lpDraft) {
      currentPlan = { days: JSON.parse(JSON.stringify(lpDraft.days)), unit: lpDraft.unit || null };
      currentTheme = lpDraft.theme || currentTheme;
      lpReadOnly = false;
    }
    /* Follow the saved state rather than assuming: a week left as a draft must not
       silently republish itself the next time somebody saves. */
    lpStatus = plan.status || 'published';

    lpCss();
    const totalActs = planCount((currentPlan && currentPlan.days) || {});
    // Saved activities still without a picture (and not removed on purpose) -> offer "Find pictures".
    const picsWanted = !lpDraft && plan.updated_at && !plan.inherited_from_centre
      && DAYS.some(d => (((currentPlan && currentPlan.days) || {})[d] || []).some(wantsPic));
    const isCurrent = viewingCurrentWeek();
    const stateChip = !plan.plan && !lpDraft
      ? '<span class="lp-chip lp-chip-none">Not planned yet</span>'
      : (lpStatus === 'draft' ? '<span class="lp-chip lp-chip-draft">Draft · staff only</span>' : '<span class="lp-chip lp-chip-pub">Published · families can see it</span>');
    /* LESSON PLANS, REDONE (2026-10-23, Anthony: "the lessons plans section under educator view
       can you make this alot better - full sweep"). One tidy toolbar (what, which week, what you
       can do), a status you can read, labelled buttons instead of bare icons, the theme as a
       line of text until you edit, and the week as five dated columns — one day at a time,
       with day tabs, on a phone. Ids and the save/load paths are unchanged. */
    container.innerHTML = `
      <div class="lp-wrap">
        <div class="lp-bar">
          <div class="lp-row">
            ${showScopeToggle ? `<label class="lp-field"><span>Plan for</span><select id="kt-scope" title="Plan for one room or the whole centre">${scopeOpts}</select></label>` : ''}
            <label class="lp-field lp-grow"><span>${activeScope === 'centre' ? 'Centre' : 'Room'}</span><select id="kt-target">
              ${activeScope==='centre'
                ? centres.map(c => `<option value="${c.id}" ${c.id==activeCentreId?'selected':''}>${esc(c.name)}</option>`).join('')
                : rooms.map(r => `<option value="${r.id}" ${r.id==activeRoomId?'selected':''}>${esc(r.name)}${r.centre_name?' · '+esc(r.centre_name):''}</option>`).join('')}
            </select></label>
            <div class="lp-field"><span>Week</span>
              <div class="lp-week">
                <button id="kt-prev-week" type="button" aria-label="Previous week" title="Previous week">‹</button>
                <label class="lp-week-label" title="Pick a week">${esc(weekRange(activeWeek))}<input type="date" id="kt-week" value="${activeWeek}" aria-label="Pick a week"></label>
                <button id="kt-next-week" type="button" aria-label="Next week" title="Next week">›</button>
              </div>
            </div>
            ${isCurrent ? '' : '<button type="button" id="kt-lp-thisweek" class="lp-btn lp-btn-ghost lp-btn-sm">This week</button>'}
          </div>
          <div class="lp-row lp-row-actions">
            <div class="lp-state">${lpReadOnly ? stateChip : ''}<span class="lp-count">${totalActs} ${totalActs === 1 ? 'activity' : 'activities'}</span></div>
            <div class="lp-actions">
              ${lpReadOnly ? `
                <div class="lp-tools">
                <button id="kt-ai" type="button" data-kt-iconized="1" class="lp-btn lp-btn-ghost" title="Draft this week with AI">✨ Draft with AI</button>
                <button id="kt-lp-templates" type="button" class="lp-btn lp-btn-ghost" data-kt-iconized="1" title="Start this week from a template">📋 Templates</button>
                ${picsWanted ? `<button id="kt-lp-pics" type="button" class="lp-btn lp-btn-ghost" data-kt-iconized="1" title="Find pictures for the activities that have none">🖼 Find pictures</button>` : ''}
                </div>
                <button id="kt-lp-edit" type="button" data-kt-iconized="1" class="lp-btn lp-btn-primary">✏️ Edit week</button>
                <button id="kt-save" type="button" hidden></button>
                <button id="kt-lp-savetpl" type="button" hidden></button>
                <select id="kt-lp-status" hidden><option value="${esc(lpStatus)}" selected></option></select>`
              : `
                <div class="lp-seg" role="group" aria-label="Who can see it">
                  <button type="button" data-st="draft" class="${lpStatus === 'draft' ? 'on' : ''}" title="Staff only">Draft</button>
                  <button type="button" data-st="published" class="${lpStatus === 'published' ? 'on' : ''}" title="Families can see it">Published</button>
                </div>
                <select id="kt-lp-status" hidden><option value="draft" ${lpStatus === 'draft' ? 'selected' : ''}>Draft</option><option value="published" ${lpStatus === 'published' ? 'selected' : ''}>Published</option></select>
                <div class="lp-tools">
                <button id="kt-ai" type="button" data-kt-iconized="1" class="lp-btn lp-btn-ghost lp-btn-sm" title="Draft this week with AI">✨ AI</button>
                <button id="kt-lp-templates" type="button" class="lp-btn lp-btn-ghost lp-btn-sm" data-kt-iconized="1">📋 Templates</button>
                <button id="kt-lp-savetpl" type="button" class="lp-btn lp-btn-ghost lp-btn-sm" data-kt-iconized="1" title="Save this week as a template your agency can reuse">Save template</button>
                </div>
                <div class="lp-commit">
                <button id="kt-lp-cancel" type="button" data-kt-iconized="1" class="lp-btn lp-btn-ghost">Cancel</button>
                <button id="kt-save" type="button" data-kt-iconized="1" class="lp-btn lp-btn-primary">💾 Save week</button>
                </div>
                <button id="kt-lp-edit" type="button" hidden></button>`}
            </div>
          </div>
        </div>

        ${lpDraft ? `<div id="kt-lp-draftbar" class="lp-note lp-note-warn">📋 <span style="flex:1;min-width:200px;"><strong>Filled from ${esc(lpDraft.source)}.</strong> Nothing is saved yet — review it, change anything you like, then press Save week.</span><button type="button" id="kt-lp-discard" data-kt-iconized="1" class="lp-btn lp-btn-ghost lp-btn-sm">Discard</button></div>` : ''}
        ${activeScope==='centre' ? `<div class="lp-note">🏫 <span><strong>Centre-wide plan.</strong> It applies to every room in the centre; families see it for any room without its own plan this week.</span></div>` : ''}
        ${unitBanner()}

        <div class="lp-theme">
          <div class="lp-theme-main">
          ${picUrl(currentCover) ? `<div class="lp-cover">${picImg(currentCover, 'lp-cover-img', currentTheme)}</div>` : ''}
          <div class="lp-theme-body">
          <div class="lp-theme-lbl">This week's theme</div>
          ${lpReadOnly
            ? `<div class="lp-theme-txt${currentTheme ? '' : ' lp-muted'}">${currentTheme ? esc(currentTheme) : 'No theme set'}</div><input id="kt-theme" type="hidden" value="${esc(currentTheme)}">`
            : `<div class="lp-theme-edit"><input id="kt-theme" placeholder="e.g. Spring & new beginnings" value="${esc(currentTheme)}"><button type="button" id="kt-lp-cover" class="lp-btn lp-btn-ghost lp-btn-sm" data-kt-iconized="1" title="A picture for this week's theme">🖼 Picture</button></div>`}
          </div>
          </div>
        </div>

        <div class="lp-daytabs" role="tablist" data-kt-noband="1">
          ${DAYS.map(d => `<button type="button" data-lpday="${d}" role="tab"><b>${DAY_LABELS[d]}</b><small>${esc(dayDate(d))}</small><i${((currentPlan.days || {})[d] || []).length ? '' : ' hidden'}>${((currentPlan.days || {})[d] || []).length}</i></button>`).join('')}
        </div>
        <div id="kt-grid" class="lp-grid"></div>

        <div id="kt-save-status" class="lp-status"></div>

        <div class="lp-legend">
          <span class="lp-legend-lbl">Learning areas</span>
          ${DOMAINS.map(d => `<span class="lp-dom" style="--c:${d.c}">${esc(d.l)}</span>`).join('')}
        </div>
        ${lpImages.can_manage ? `<div class="lp-picopt"><label><input type="checkbox" id="kt-lp-autopics" ${lpImages.auto ? 'checked' : ''}> Add pictures to activities automatically</label>
          <div>Openly licensed pictures (no people) are looked up for each activity and the week's theme${lpImages.ai ? ', chosen with AI' : ''}. Staff can always change or remove one, or upload their own.</div></div>` : ''}
      </div>
    `;

    renderGrid();
    // Phone: one day at a time. Opens on today when this week is on screen, else Monday.
    (function () {
      const tabs = container.querySelectorAll('[data-lpday]');
      const show = (d) => {
        lpDay = d;
        tabs.forEach(t => t.classList.toggle('on', t.getAttribute('data-lpday') === d));
        container.querySelectorAll('#kt-grid .lp-day').forEach(c => c.classList.toggle('on', c.getAttribute('data-day') === d));
      };
      tabs.forEach(t => t.addEventListener('click', () => show(t.getAttribute('data-lpday'))));
      const today = isCurrent ? agencyWeekdayKey() : null;
      show(DAYS.indexOf(lpDay) > -1 ? lpDay : (DAYS.indexOf(today) > -1 ? today : 'monday'));
    })();
    const thisWeek = $('#kt-lp-thisweek', container);
    if (thisWeek) thisWeek.addEventListener('click', () => { activeWeek = mondayOf(new Date()); lpDay = null; renderProvider(container); });
    const cancelBtn = $('#kt-lp-cancel', container);
    if (cancelBtn) cancelBtn.addEventListener('click', () => { pendingDraft = null; lpReadOnly = true; renderProvider(container); });
    container.querySelectorAll('.lp-seg [data-st]').forEach(b => b.addEventListener('click', () => {
      lpStatus = b.getAttribute('data-st');
      container.querySelectorAll('.lp-seg [data-st]').forEach(x => x.classList.toggle('on', x === b));
      const sel = $('#kt-lp-status', container); if (sel) sel.value = lpStatus;
    }));

    if ($('#kt-scope', container)) {
      $('#kt-scope', container).addEventListener('change', (e) => { activeScope = e.target.value; renderProvider(container); });
    }
    $('#kt-target', container).addEventListener('change', (e) => {
      if (activeScope === 'centre') activeCentreId = parseInt(e.target.value, 10);
      else activeRoomId = parseInt(e.target.value, 10);
      renderProvider(container);
    });
    $('#kt-week', container).addEventListener('change', (e) => { activeWeek = mondayOf(e.target.value); renderProvider(container); });
    /* NOON, not midnight. `new Date('2026-09-07')` parses as UTC midnight, which in
       Toronto is Sep 6 at 20:00 — so getDate() answered 6, +7 landed on Sunday the
       13th, and mondayOf() (which reads LOCAL parts) snapped that straight back to
       Sep 7. The forward button could not move the week at all, and back skipped two.
       Parsing at local noon is the same trick viewingCurrentWeek() above already uses,
       and it is immune to both the UTC offset and DST. */
    const weekCursor = () => new Date(activeWeek + 'T12:00:00');
    const stepWeek = (days) => { const d = weekCursor(); d.setDate(d.getDate() + days); activeWeek = mondayOf(d); renderProvider(container); };
    $('#kt-prev-week', container).addEventListener('click', () => stepWeek(-7));
    $('#kt-next-week', container).addEventListener('click', () => stepWeek(7));
    $('#kt-save', container).addEventListener('click', () => save(container));

    /* Edit is a deliberate act. Re-rendering rather than toggling attributes in
       place keeps one render path responsible for both modes. */
    var lpEditBtn = $('#kt-lp-edit', container);
    if (lpEditBtn) {
      lpEditBtn.addEventListener('click', function () {
        lpReadOnly = false;
        renderProvider(container);
      });
    }
    var lpStatusEl = $('#kt-lp-status', container);
    if (lpStatusEl) {
      lpStatusEl.addEventListener('change', function () { lpStatus = lpStatusEl.value; });
    }
    $('#kt-ai', container).addEventListener('click', () => openAiDialog(container));
    const picsBtn = $('#kt-lp-pics', container);
    if (picsBtn) picsBtn.addEventListener('click', () => { picsBtn.disabled = true; fillPictures(container, true); });
    const coverBtn = $('#kt-lp-cover', container);
    if (coverBtn) coverBtn.addEventListener('click', () => openCoverDialog(container));
    const autoBox = $('#kt-lp-autopics', container);
    if (autoBox) autoBox.addEventListener('change', async () => {
      const on = autoBox.checked;
      try {
        await api('PUT', '/provider/lesson-plans/image-settings', Object.assign({ auto: on }, scopeParams()));
        lpImages.auto = on;
        if (window.KT && KT.toast) KT.toast('🖼', 'Lesson plan pictures', on ? 'Pictures are added automatically.' : 'Pictures are no longer added automatically.');
      } catch (e) {
        autoBox.checked = !on;
        if (window.KT && KT.toast) KT.toast('⚠️', 'Could not change it', e.message || '', '#B91C1C');
      }
    });
    $('#kt-lp-templates', container).addEventListener('click', () => openTemplates(container));
    var saveTplBtn = $('#kt-lp-savetpl', container);
    if (saveTplBtn) saveTplBtn.addEventListener('click', () => saveAsTemplate(container));
    var discardBtn = $('#kt-lp-discard', container);
    if (discardBtn) discardBtn.addEventListener('click', () => { pendingDraft = null; lpReadOnly = true; renderProvider(container); });
  }

  /* ═════════ Weekly templates (2026-09-29) ═════════
     Anthony: "add lesson plan templates that educators can choose from optionally for
     each week (allow them to review it first before choosing)". Browse -> preview the
     whole week -> Use this template, which fills the planner as an unsaved draft. */
  const AGE_LABEL = { infant: 'Infant', toddler: 'Toddler', preschool: 'Preschool', school_age: 'School age', mixed: 'Mixed ages' };
  const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
  let tplCache = null;

  function tplOverlay() {
    var ov = document.createElement('div');
    ov.className = 'kt-modal-overlay kt-lp-tpl';
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;z-index:10000;padding:16px;';
    ov.innerHTML = '<div class="kt-lp-tpl-box" style="background:#fff;border-radius:16px;max-width:1100px;width:100%;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;"></div>';
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);
    return ov;
  }
  function planCount(days) {
    return WEEKDAYS.reduce(function (n, d) { return n + ((days && days[d]) || []).length; }, 0);
  }

  async function openTemplates(container) {
    var ov = tplOverlay();
    var box = ov.querySelector('.kt-lp-tpl-box');
    box.innerHTML = '<div style="padding:28px;color:#6B7280;">Loading templates…</div>';
    try { if (!tplCache) tplCache = (await api('GET', '/provider/lesson-plan-templates')).templates || []; }
    catch (e) { box.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load templates: ' + esc(e.message) + '</div>'; return; }
    var age = 'all', q = '';
    function list() {
      var ages = ['all'].concat(Object.keys(AGE_LABEL).filter(function (a) { return tplCache.some(function (t) { return t.age_group === a; }); }));
      var shown = tplCache.filter(function (t) {
        if (age === 'own' && t.source !== 'agency') return false;
        if (age !== 'all' && age !== 'own' && t.age_group !== age) return false;
        var hay = (t.title + ' ' + (t.theme || '') + ' ' + (t.description || '')).toLowerCase();
        return !q || hay.indexOf(q.toLowerCase()) !== -1;
      });
      var chip = function (v, l) {
        var on = v === age;
        return '<button type="button" data-age="' + v + '" style="height:30px;padding:0 12px;border-radius:999px;font-size:12.5px;font-weight:800;cursor:pointer;border:1px solid ' + (on ? '#1F6080;background:#1F6080;color:#fff;' : '#CBD5E1;background:#fff;color:#334155;') + '">' + l + '</button>';
      };
      box.innerHTML =
        '<div style="padding:18px 20px 12px;border-bottom:1px solid #EEF2F6;">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><h3 style="margin:0;font-size:19px;color:#0F172A;">📋 Weekly lesson plan templates</h3>'
        + '<button type="button" data-close style="border:0;background:none;font-size:22px;cursor:pointer;color:#64748B;" aria-label="Close">×</button></div>'
        + '<div style="font-size:13px;color:#64748B;margin:4px 0 12px;">Optional starting points for the week of ' + esc(activeWeek) + '. Open one to review the whole week before you use it.</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
        + '<input type="search" data-q placeholder="Search themes…" value="' + esc(q) + '" style="height:32px;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;min-width:200px;">'
        + ages.map(function (a) { return chip(a, a === 'all' ? 'All' : AGE_LABEL[a]); }).join('')
        + (tplCache.some(function (t) { return t.source === 'agency'; }) ? chip('own', '🏠 Our agency') : '')
        + '</div></div>'
        + '<div style="padding:16px 20px;overflow:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;">'
        + (shown.length ? shown.map(function (t) {
            return '<button type="button" data-tpl="' + t.id + '" style="text-align:left;border:1px solid #E2E8F0;background:#fff;border-radius:12px;padding:14px;cursor:pointer;display:flex;flex-direction:column;gap:6px;">'
              + '<div style="display:flex;gap:6px;flex-wrap:wrap;"><span style="font-size:11px;font-weight:800;color:#1F6080;background:#EAF3F6;padding:2px 8px;border-radius:999px;">' + esc(AGE_LABEL[t.age_group] || t.age_group) + '</span>'
              + (t.source === 'agency' ? '<span style="font-size:11px;font-weight:800;color:#92400E;background:#FEF3C7;padding:2px 8px;border-radius:999px;">Our agency</span>' : '') + '</div>'
              + '<div style="font-weight:800;font-size:15px;color:#0F172A;">' + esc(t.title) + '</div>'
              + '<div style="font-size:12.5px;color:#64748B;line-height:1.45;">' + esc(t.description || t.theme || '') + '</div>'
              + '<div style="font-size:12px;color:#94A3B8;margin-top:auto;">' + t.activity_count + ' activities · Mon–Fri' + (t.created_by ? ' · by ' + esc(t.created_by) : '') + '</div>'
              + '</button>';
          }).join('') : '<div style="color:#64748B;font-size:14px;">No templates match.</div>')
        + '</div>';
      box.querySelector('[data-close]').onclick = function () { ov.remove(); };
      box.querySelectorAll('[data-age]').forEach(function (b) { b.onclick = function () { age = b.getAttribute('data-age'); list(); }; });
      var qi = box.querySelector('[data-q]');
      qi.oninput = function () { q = qi.value; var pos = qi.selectionStart; list(); var n = box.querySelector('[data-q]'); n.focus(); try { n.setSelectionRange(pos, pos); } catch (e) {} };
      box.querySelectorAll('[data-tpl]').forEach(function (b) {
        b.onclick = function () { preview(tplCache.filter(function (t) { return String(t.id) === b.getAttribute('data-tpl'); })[0]); };
      });
    }
    function preview(t) {
      var days = (t.plan && t.plan.days) || {};
      box.innerHTML =
        '<div style="padding:16px 20px;border-bottom:1px solid #EEF2F6;display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;">'
        + '<div style="min-width:240px;flex:1;"><button type="button" data-back style="border:0;background:none;color:#1F6080;font-weight:800;cursor:pointer;padding:0;font-size:13px;">‹ All templates</button>'
        + '<h3 style="margin:6px 0 2px;font-size:20px;color:#0F172A;">' + esc(t.title) + '</h3>'
        + '<div style="font-size:13px;color:#64748B;">' + esc(AGE_LABEL[t.age_group] || t.age_group) + ' · ' + t.activity_count + ' activities' + (t.theme && t.theme !== t.title ? ' · Theme: ' + esc(t.theme) : '') + '</div>'
        + (t.description ? '<div style="font-size:13.5px;color:#334155;margin-top:6px;max-width:720px;line-height:1.5;">' + esc(t.description) + '</div>' : '') + '</div>'
        + '<div style="display:flex;gap:8px;align-items:center;">'
        + (t.can_delete ? '<button type="button" data-del style="height:36px;padding:0 12px;border-radius:8px;border:1px solid #FCA5A5;background:#fff;color:#B91C1C;font-weight:700;font-size:13px;cursor:pointer;">Remove template</button>' : '')
        + (lpReadOnly && !pendingDraft && false ? '' : '')
        + '<button type="button" data-use style="height:36px;padding:0 16px;border-radius:8px;border:0;background:#1F6080;color:#fff;font-weight:800;font-size:13.5px;cursor:pointer;">Use this template</button></div></div>'
        + '<div style="padding:16px 20px;overflow:auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;">'
        + WEEKDAYS.map(function (d) {
            return '<div style="border:1px solid #E2E8F0;border-radius:12px;overflow:hidden;"><div style="background:' + (DAY_COLORS[d] || '#1F6080') + ';color:#fff;font-weight:800;font-size:13px;padding:7px 10px;text-transform:capitalize;">' + d + '</div>'
              + '<div style="padding:8px;display:flex;flex-direction:column;gap:8px;">'
              + ((days[d] || []).map(function (a) {
                  var dom = DOMAINS.find(function (x) { return x.v === a.domain; });
                  return '<div style="--kt-edge:' + (dom ? dom.c : '#CBD5E1') + ';padding:4px 8px;">'
                    + '<div style="font-size:11.5px;color:#64748B;">' + esc(fmtTime(a.time) || '') + (dom ? ' · ' + esc(dom.l) : '') + '</div>'
                    + '<div style="font-weight:800;font-size:13px;color:#0F172A;">' + esc(a.title) + '</div>'
                    + (a.notes ? '<div style="font-size:12px;color:#475569;line-height:1.45;margin-top:2px;">' + esc(a.notes) + '</div>' : '') + '</div>';
                }).join('') || '<div style="font-size:12px;color:#94A3B8;">Nothing planned</div>')
              + '</div></div>';
          }).join('')
        + '</div>';
      box.querySelector('[data-back]').onclick = list;
      var del = box.querySelector('[data-del]');
      if (del) del.onclick = async function () {
        var ok = window.KT && KT.confirm ? await KT.confirm({ title: 'Remove this template?', description: '"' + t.title + '" will no longer be offered to your agency. Weeks already planned from it are not changed.', okLabel: 'Remove' }) : true;
        if (!ok) return;
        try { await api('DELETE', '/provider/lesson-plan-templates/' + t.id); tplCache = null; ov.remove(); openTemplates(container); }
        catch (e) { if (KT.toast) KT.toast('⚠️', 'Not removed', e.message, '#B91C1C'); }
      };
      box.querySelector('[data-use]').onclick = async function () {
        var existing = planCount(currentPlan && currentPlan.days);
        var mode = 'replace';
        if (existing > 0) {
          mode = await chooseMode(existing);
          if (!mode) return;
        }
        var days = {};
        WEEKDAYS.forEach(function (d) {
          var add = JSON.parse(JSON.stringify(((t.plan && t.plan.days) || {})[d] || []));
          days[d] = mode === 'add' ? (((currentPlan && currentPlan.days && currentPlan.days[d]) || []).concat(add)) : add;
        });
        var theme = mode === 'add' && currentTheme ? currentTheme : (t.theme || t.title);
        pendingDraft = { key: draftKey(), theme: theme, days: days, source: 'the "' + t.title + '" template' };
        lpReadOnly = false;
        ov.remove();
        try { api('POST', '/provider/lesson-plan-templates/' + t.id + '/used', {}); } catch (e) {}
        renderProvider(container);
        if (window.KT && KT.toast) KT.toast('📋', 'Template added', 'Review the week, change anything, then Save.');
      };
    }
    function chooseMode(existing) {
      return new Promise(function (resolve) {
        var m = document.createElement('div');
        m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.35);display:flex;align-items:center;justify-content:center;z-index:10001;padding:16px;';
        m.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:440px;width:100%;padding:20px;">'
          + '<h3 style="margin:0 0 8px;font-size:17px;color:#0F172A;">This week already has ' + existing + ' activit' + (existing === 1 ? 'y' : 'ies') + '</h3>'
          + '<div style="font-size:13.5px;color:#475569;line-height:1.5;">Replace them with the template, or add the template\'s activities alongside them? Nothing is saved until you press Save.</div>'
          + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap;">'
          + '<button type="button" data-m="" style="background:#F1F5F9;color:#334155;border:0;padding:9px 14px;border-radius:8px;font-weight:700;cursor:pointer;">Cancel</button>'
          + '<button type="button" data-m="add" style="background:#fff;color:#1F6080;border:1px solid #CFE3EB;padding:9px 14px;border-radius:8px;font-weight:800;cursor:pointer;">Add to this week</button>'
          + '<button type="button" data-m="replace" style="background:#1F6080;color:#fff;border:0;padding:9px 14px;border-radius:8px;font-weight:800;cursor:pointer;">Replace</button></div></div>';
        document.body.appendChild(m);
        m.querySelectorAll('[data-m]').forEach(function (b) { b.onclick = function () { m.remove(); resolve(b.getAttribute('data-m') || null); }; });
      });
    }
    list();
  }

  function saveAsTemplate(container) {
    var days = (currentPlan && currentPlan.days) || {};
    var n = planCount(days);
    var ov = tplOverlay();
    var box = ov.querySelector('.kt-lp-tpl-box');
    box.style.maxWidth = '460px';
    var fld = 'width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;';
    var lbl = 'display:block;font-size:12px;font-weight:700;color:#374151;margin:10px 0 4px;';
    var theme = ($('#kt-theme', container) || {}).value || currentTheme || '';
    box.innerHTML = '<div style="padding:20px;">'
      + '<h3 style="margin:0 0 6px;font-size:18px;color:#0F172A;">💾 Save this week as a template</h3>'
      + '<div style="font-size:13px;color:#64748B;">' + (n ? n + ' activities. Everyone in your agency will be able to preview it and use it for a future week.' : 'This week has no activities yet.') + '</div>'
      + '<label style="' + lbl + '">Name</label><input data-title maxlength="80" style="' + fld + '" value="' + esc(theme) + '">'
      + '<label style="' + lbl + '">Age group</label><select data-age style="' + fld + '">'
      + Object.keys(AGE_LABEL).map(function (a) { return '<option value="' + a + '"' + (a === 'preschool' ? ' selected' : '') + '>' + AGE_LABEL[a] + '</option>'; }).join('') + '</select>'
      + '<label style="' + lbl + '">Short description (optional)</label><textarea data-desc rows="2" maxlength="300" style="' + fld + 'resize:vertical;"></textarea>'
      + '<div data-err style="color:#B91C1C;font-size:13px;min-height:18px;margin-top:8px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;"><button type="button" data-cancel style="background:#F1F5F9;color:#334155;border:0;padding:9px 16px;border-radius:8px;font-weight:700;cursor:pointer;">Cancel</button>'
      + '<button type="button" data-ok style="background:#1F6080;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-weight:800;cursor:pointer;"' + (n ? '' : ' disabled') + '>Save template</button></div></div>';
    box.querySelector('[data-cancel]').onclick = function () { ov.remove(); };
    box.querySelector('[data-ok]').onclick = async function () {
      var title = box.querySelector('[data-title]').value.trim();
      if (!title) { box.querySelector('[data-err]').textContent = 'Give the template a name.'; return; }
      this.disabled = true;
      try {
        await api('POST', '/provider/lesson-plan-templates', { title: title, theme: theme || title, age_group: box.querySelector('[data-age]').value,
          description: box.querySelector('[data-desc]').value.trim() || null, plan: { days: days } });
        tplCache = null;
        ov.remove();
        if (window.KT && KT.toast) KT.toast('💾', 'Template saved', '"' + title + '" is now in 📋 Templates for your agency.');
      } catch (e) { this.disabled = false; box.querySelector('[data-err]').textContent = e.message; }
    };
  }

  /* Planner times were free text, so "10am", "1030am" and "10:00" all appear in real
     plans. toHHMM feeds the native picker; fmtTime is what everything else displays.
     Both mirror App\Support\LessonPlans on the server so the planner, the parent
     summary and the educator recap never disagree about what a time says. */
  function toHHMM(raw) {
    var t = String(raw == null ? '' : raw).toLowerCase().trim();
    if (!t) return '';
    var mer = null, m = t.match(/(a\.?m\.?|p\.?m\.?)\s*$/);
    if (m) { mer = m[1].charAt(0) === 'a' ? 'AM' : 'PM'; t = t.replace(/(a\.?m\.?|p\.?m\.?)\s*$/, '').trim(); }
    t = t.replace(/[ .]/g, '');
    var h, min;
    if (/^\d{1,2}:\d{2}$/.test(t)) { var p = t.split(':'); h = +p[0]; min = +p[1]; }
    else if (/^\d{3,4}$/.test(t)) { h = +t.slice(0, -2); min = +t.slice(-2); }
    else if (/^\d{1,2}$/.test(t)) { h = +t; min = 0; }
    else return '';                       // not a time — leave it to the text input
    if (h > 23 || min > 59) return '';
    if (mer === 'PM' && h < 12) h += 12;
    if (mer === 'AM' && h === 12) h = 0;
    if (mer === null && h < 7) h += 12;   // a childcare 1–6 means the afternoon
    return (h < 10 ? '0' : '') + h + ':' + (min < 10 ? '0' : '') + min;
  }

  function fmtTime(raw) {
    var hhmm = toHHMM(raw);
    if (!hhmm) return String(raw == null ? '' : raw);   // "after lunch" stays as typed
    var p = hhmm.split(':'), h = +p[0], mer = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    return h + ':' + p[1] + ' ' + mer;
  }

  function renderGrid() {
    const grid = $('#kt-grid');
    if (!grid) return;
    if (!currentPlan || !currentPlan.days) currentPlan = { days: {} };
    DAYS.forEach(d => { if (!Array.isArray(currentPlan.days[d])) currentPlan.days[d] = []; });

    grid.innerHTML = DAYS.map(day => dayColumn(day, currentPlan.days[day])).join('');
    if (lpDay) grid.querySelectorAll('.lp-day').forEach(c => c.classList.toggle('on', c.getAttribute('data-day') === lpDay));
    // Keep the phone's day-tab counts in step after an edit.
    document.querySelectorAll('[data-lpday]').forEach(t => { const i = t.querySelector('i'), n = (currentPlan.days[t.getAttribute('data-lpday')] || []).length; if (i) { i.textContent = n; i.hidden = !n; } });

    /* Read-only shows the SAME cards, just not tappable — activityCard() decides that
       from lpReadOnly, so the two modes render from one path and cannot drift apart.
       Only the add button has to be taken away. */
    if (lpReadOnly) {
      grid.querySelectorAll('.kt-add-activity').forEach(function (b) { b.style.display = 'none'; });
    }

    // Adding and editing are the same dialog; -1 means "new".
    grid.querySelectorAll('.kt-add-activity').forEach(b => b.addEventListener('click', () => {
      openActivityDialog(b.dataset.day, -1);
    }));
    grid.querySelectorAll('.kt-activity-tap').forEach(card => {
      const open = () => openActivityDialog(card.dataset.day, parseInt(card.dataset.idx, 10));
      card.addEventListener('click', open);
      // Keyboard parity: the card is role="button", so it must answer to one.
      card.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      });
    });
  }

  function dayColumn(day, activities) {
    const dc = DAY_COLORS[day] || '#64748B';
    /* Only tint when the week on screen contains today — otherwise every week
       would highlight a Wednesday, which tells the reader nothing. */
    const isToday = viewingCurrentWeek() && agencyWeekdayKey() === day;
    return `
      <section class="lp-day${isToday ? ' lp-today' : ''}" data-day="${day}" style="--dc:${dc}">
        <header>
          <span class="lp-dname">${DAY_LABELS[day]}</span>
          <span class="lp-ddate">${esc(dayDate(day))}</span>
          ${isToday ? '<span class="lp-dtoday">Today</span>' : ''}
          <span class="lp-dcount">${activities.length || ''}</span>
        </header>
        ${activities.length ? activities.map((a, i) => activityCard(day, i, a)).join('')
          : `<div class="lp-empty">${lpReadOnly ? 'Nothing planned' : 'Nothing planned yet'}</div>`}
        <button class="kt-add-activity lp-add" data-day="${day}" type="button">+ Add activity</button>
      </section>
    `;
  }

  /* A SUMMARY, NOT FOUR CONTROLS.

     Every activity used to be a time picker, a title box, a domain select and a notes
     textarea, all live, stacked inside a column one fifth of the screen wide. On a phone
     that is four fiddly targets per activity and five activities per column — the reason
     the weekly menu was moved to a dialog, and the same answer applies here.

     The card now shows what is planned; tapping it while editing opens one dialog with
     room to actually type. Read-only shows the identical card, just not tappable, so the
     two modes cannot drift apart. (Anthony, 2026-09-08) */
  function activityCard(day, idx, a) {
    const domain = DOMAINS.find(d => d.v === a.domain);
    // Domain colour when set; otherwise a distinct palette colour by position so
    // template-added (domain-less) activities are still easy to tell apart.
    const colour = domain ? domain.c : ACTIVITY_PALETTE[idx % ACTIVITY_PALETTE.length];
    const title = (a.title || '').trim();
    const when = (a.time || '').trim();
    const notes = (a.notes || '').trim();
    const tappable = !lpReadOnly;
    return `
      <div class="kt-activity lp-act${tappable ? ' kt-activity-tap' : ''}" data-day="${day}" data-idx="${idx}" style="--ac:${colour}"
           ${tappable ? 'role="button" tabindex="0" title="Tap to edit"' : ''}>
        ${picImg(a, 'lp-pic', title)}
        <div class="lp-act-top">
          ${when ? `<span class="lp-time">${esc(fmtTime(when))}</span>` : ''}
          <span class="lp-title${title ? '' : ' lp-muted'}">${title ? esc(title) : (tappable ? 'Tap to fill in' : '—')}</span>
        </div>
        ${domain ? `<span class="lp-dom lp-dom-sm" style="--c:${domain.c}">${esc(domain.l)}</span>` : ''}
        ${(a.foundations || []).length ? `<div style="display:flex;flex-wrap:wrap;gap:3px;margin:3px 0 0;">${fndChips(a)}</div>` : ''}
        ${notes ? `<div class="lp-notes">${esc(notes)}</div>` : ''}
      </div>
    `;
  }

  /* ONE DIALOG for an activity — new (idx === -1) or existing. Mirrors the weekly
     menu's dish dialog so the two screens behave the same way. */
  function openActivityDialog(day, idx) {
    const isNew = idx < 0;
    const a = isNew ? { time: '', title: '', domain: null, notes: '' }
                    : (currentPlan.days[day][idx] || { time: '', title: '', domain: null, notes: '' });
    const hhmm = toHHMM(a.time);
    // A picker when the value is a clock time (or blank), a text box when it is not, so
    // an existing "after lunch" is not silently discarded by type="time".
    const isTime = hhmm !== '' || !(a.time || '').trim();

    const ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:20px;';
    ov.innerHTML = `<div role="dialog" aria-modal="true" style="background:#fff;border-radius:16px;max-width:440px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 24px 60px rgba(15,23,42,.35);">
      <div style="padding:16px 20px;border-bottom:1px solid #EDF2F7;">
        <div style="font-size:16px;font-weight:800;color:#0D1B2A;">${isNew ? 'Add an activity' : 'Edit activity'} &middot; ${esc(DAY_LABELS[day] || day)}</div>
        <div style="font-size:12.5px;color:#64748B;margin-top:2px;">What is planned, and when.</div>
      </div>
      <div style="padding:16px 20px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">Time</label>
        ${isTime
          ? `<input id="lp-time" type="time" value="${esc(hhmm)}" style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;">`
          : `<input id="lp-time" type="text" value="${esc(a.time || '')}" placeholder="9:00" title="Not a clock time — clear it to use the time picker." style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;">`}
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">Activity</label>
        <input id="lp-title" value="${esc(a.title || '')}" placeholder="e.g. Playdough"
               style="width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">Domain</label>
        <select id="lp-domain" style="width:100%;box-sizing:border-box;height:42px;padding:0 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;background:#fff;">
          <option value="">— Domain —</option>
          ${DOMAINS.map(d => `<option value="${d.v}" ${d.v === a.domain ? 'selected' : ''}>${esc(d.l)}</option>`).join('')}
        </select>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">HDLH foundations</label>
        <div id="lp-fnd" style="display:flex;flex-wrap:wrap;gap:6px 12px;font-size:13px;" data-kt-noswitch>
          ${Object.keys(FND).map(k => `<label style="display:inline-flex;align-items:center;gap:5px;font-weight:600;color:#334155;cursor:pointer;"><input type="checkbox" value="${k}" ${((a.foundations || []).indexOf(k) !== -1) ? 'checked' : ''} style="width:15px;height:15px;"> ${FND[k].i} ${FND[k].l}</label>`).join('')}
        </div>
        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin:12px 0 4px;">Notes</label>
        <textarea id="lp-notes" rows="3" placeholder="Optional"
                  style="display:block;width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E2E8F0;border-radius:9px;font:inherit;font-size:14px;resize:vertical;">${esc(a.notes || '')}</textarea>
        <div class="lp-ph-wrap">${picSection('Picture')}</div>
      </div>
      <div style="padding:14px 20px;border-top:1px solid #EDF2F7;display:flex;gap:8px;align-items:center;">
        ${isNew ? '' : '<button id="lp-del" style="background:#fff;border:1px solid #FECACA;color:#B91C1C;border-radius:10px;padding:10px 14px;font:inherit;font-size:14px;font-weight:700;cursor:pointer;">Delete</button>'}
        <button id="lp-x" style="margin-left:auto;background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:10px 16px;font:inherit;font-size:14px;color:#475569;cursor:pointer;">Cancel</button>
        <button id="lp-ok" style="background:linear-gradient(135deg,#1F6080,#2c7894);border:0;border-radius:10px;padding:10px 20px;font:inherit;font-size:14px;font-weight:700;color:#fff;cursor:pointer;">Done</button>
      </div>
    </div>`;
    document.body.appendChild(ov);
    try { if (window.KT && KT.pushOverlay) { KT.pushOverlay(ov); } } catch (e) {}

    const close = () => {
      try { if (window.KT && KT.popOverlay) { KT.popOverlay(ov); } } catch (e) {}
      ov.remove();
    };
    lpCss();
    const pic = wirePic(ov.querySelector('.lp-ph-wrap'), a, () => ({
      title: ov.querySelector('#lp-title').value.trim(), notes: ov.querySelector('#lp-notes').value.trim(),
      theme: ($('#kt-theme') || {}).value || currentTheme || '' }));
    const t = ov.querySelector('#lp-title');
    if (t && window.innerWidth > 768) { t.focus(); t.select(); }   // on a phone the keyboard waits for a tap
    ov.querySelector('#lp-x').onclick = close;
    ov.addEventListener('click', e => { if (e.target === ov) close(); });
    ov.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    const del = ov.querySelector('#lp-del');
    if (del) {
      del.onclick = () => { currentPlan.days[day].splice(idx, 1); close(); renderGrid(); };
    }
    ov.querySelector('#lp-ok').onclick = () => {
      const next = {
        time: ov.querySelector('#lp-time').value.trim(),
        title: ov.querySelector('#lp-title').value.trim(),
        domain: ov.querySelector('#lp-domain').value || null,
        notes: ov.querySelector('#lp-notes').value.trim(),
      };
      const fnd = Array.prototype.map.call(ov.querySelectorAll('#lp-fnd input:checked'), c => c.value);
      if (fnd.length) next.foundations = fnd;
      if (a.unit_activity) next.unit_activity = a.unit_activity;   // which unit activity it came from
      /* An empty new activity is not worth a card. An existing one emptied out is a
         deliberate clear, and Delete is right there for removing it outright. */
      if (isNew && !next.time && !next.title && !next.notes && !next.domain) { close(); return; }
      // The picture travels with the activity. An automatic one belongs to the old title, so a new title gets a new one.
      Object.assign(next, picFields(pic));
      if (!isNew && pic.image_source === 'auto' && next.title !== (a.title || '').trim()) {
        PIC_FIELDS.forEach(f => { next[f] = null; });
      }
      if (isNew) { currentPlan.days[day].push(next); }
      else { currentPlan.days[day][idx] = next; }
      close();
      renderGrid();
    };
  }

  async function save(container) {
    const status = $('#kt-save-status', container);
    status.style.color = '#6B7280';
    status.textContent = 'Saving…';
    currentTheme = $('#kt-theme', container).value;
    try {
      const payload = { week_starting: activeWeek, theme: currentTheme, plan: currentPlan,
                        status: lpStatus };
      if (currentCover) payload.cover = picFields(currentCover);
      if (activeScope === 'centre') payload.centre_id = activeCentreId;
      else payload.room_id = activeRoomId;
      const saved = await api('PUT', '/provider/lesson-plans', payload);
      const needPics = saved && saved.pictures && saved.pictures.auto && saved.pictures.missing > 0;
      pendingDraft = null;
      status.style.color = '#16A34A';
      status.textContent = (lpStatus === 'published' ? '✓ Published ' : '✓ Saved as draft ')
        + (activeScope === 'centre' ? '(whole centre) ' : '')
        + 'at ' + new Date().toLocaleTimeString();
      /* Back to reading once it is saved, so the week cannot be edited by accident
         after the person has finished with it. */
      lpReadOnly = true;
      await renderProvider(container);
      if (needPics) fillPictures(container, false);
    } catch (e) {
      status.style.color = '#DC2626';
      status.textContent = '✗ ' + e.message;
    }
  }

  /** The week's theme picture: the same Picture block as an activity, in a small dialog of its own. */
  function openCoverDialog(container) {
    lpCss();
    const ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:20px;';
    ov.innerHTML = `<div role="dialog" aria-modal="true" style="background:#fff;border-radius:16px;max-width:440px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 24px 60px rgba(15,23,42,.35);">
      <div style="padding:16px 20px;border-bottom:1px solid #EDF2F7;">
        <div style="font-size:16px;font-weight:800;color:#0D1B2A;">Picture for this week's theme</div>
        <div style="font-size:12.5px;color:#64748B;margin-top:2px;">Shown above the plan for families and in the daily summary.</div>
      </div>
      <div style="padding:16px 20px;"><div class="lp-ph-wrap">${picSection('Picture')}</div></div>
      <div style="padding:14px 20px;border-top:1px solid #EDF2F7;display:flex;gap:8px;justify-content:flex-end;">
        <button id="lp-cx" style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:10px 16px;font:inherit;font-size:14px;color:#475569;cursor:pointer;">Cancel</button>
        <button id="lp-cok" style="background:linear-gradient(135deg,#1F6080,#2c7894);border:0;border-radius:10px;padding:10px 20px;font:inherit;font-size:14px;font-weight:700;color:#fff;cursor:pointer;">Done</button>
      </div>
    </div>`;
    document.body.appendChild(ov);
    try { if (window.KT && KT.pushOverlay) { KT.pushOverlay(ov); } } catch (e) {}
    const close = () => { try { if (window.KT && KT.popOverlay) { KT.popOverlay(ov); } } catch (e) {} ov.remove(); };
    const themeNow = () => (($('#kt-theme', container) || {}).value || '').trim();
    const pic = wirePic(ov.querySelector('.lp-ph-wrap'), currentCover, () => ({ title: themeNow(), notes: '', theme: '' }));
    ov.querySelector('#lp-cx').onclick = close;
    ov.addEventListener('click', e => { if (e.target === ov) close(); });
    ov.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    ov.querySelector('#lp-cok').onclick = () => {
      currentCover = picFields(pic);
      close();
      // Shown in place: re-rendering would reload the saved week over the unsaved edits.
      const c = container.querySelector('.lp-theme-main');
      if (c) {
        const old = c.querySelector('.lp-cover'); if (old) old.remove();
        if (picUrl(currentCover)) c.insertAdjacentHTML('afterbegin', '<div class="lp-cover">' + picImg(currentCover, 'lp-cover-img', themeNow()) + '</div>');
      }
    };
  }

  async function renderParent(container) {
    container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">Loading…</div>';

    // Find user's children
    let kids = [];
    try {
      const me = await api('GET', '/parent/children');
      kids = me.children || [];
    } catch (e) {
      container.innerHTML = '<div style="padding:24px;color:#DC2626;">Could not load: ' + esc(e.message) + '</div>';
      return;
    }

    if (kids.length === 0) {
      container.innerHTML = '<div style="padding:32px;text-align:center;color:#6B7280;">No children found on your account.</div>';
      return;
    }

    lpCss();
    container.innerHTML = '<div style="padding:24px;max-width:1800px;"><h2 style="font-size:24px;margin:0 0 16px;">📚 This Week\'s Activities</h2><div id="kt-kids-plans"></div></div>';
    const mount = $('#kt-kids-plans', container);

    for (const child of kids) {
      try {
        const plan = await api('GET', '/parent/lesson-plan/' + child.id);
        mount.insertAdjacentHTML('beforeend', parentPlanCard(child, plan));
      } catch (e) {
        mount.insertAdjacentHTML('beforeend', `<div style="background:white;border-radius:12px;padding:18px;margin-bottom:12px;color:#6B7280;">${esc(child.first_name)}: ${esc(e.message)}</div>`);
      }
    }
  }

  function parentPlanCard(child, plan) {
    const days = plan.plan && plan.plan.days ? plan.plan.days : {};
    const empty = !DAYS.some(d => (days[d] || []).length > 0);
    return `
      <div style="background:white;border-radius:14px;padding:20px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
          <div style="font-weight:700;font-size:18px;">${esc(child.first_name)} ${esc(child.last_name||'')}</div>
          <div style="font-size:13px;color:#6B7280;">Week of ${esc(plan.week_starting)}</div>
        </div>
        ${picUrl(plan.cover) ? `<div class="lp-pcover">${picImg(plan.cover, 'lp-pcover-img', plan.theme || '')}${plan.cover.image_credit_required && plan.cover.image_credit ? `<div class="lp-credit">Picture: ${esc(plan.cover.image_credit)}</div>` : ''}</div>` : ''}
        ${plan.theme ? `<div style="font-size:14px;color:#1F6080;margin-bottom:12px;"><em>Theme:</em> <strong>${esc(plan.theme)}</strong></div>` : ''}
        ${empty
          ? '<div style="color:#64748B;font-style:italic;">No plan posted yet for this week.</div>'
          : `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
              ${DAYS.map(d => parentDayCard(d, days[d] || [])).join('')}
            </div>`
        }
        ${(!empty && plan.plan && plan.plan.unit && (plan.plan.unit.family || []).length) ? `<div style="margin-top:12px;background:#F0FDFA;border:1px solid #99F6E4;border-radius:10px;padding:10px 12px;font-size:13px;color:#134E4A;">
            <div style="font-weight:800;margin-bottom:4px;">🏡 Try at home this week</div>
            <ul style="margin:0;padding-left:18px;">${plan.plan.unit.family.slice(0, 4).map(x => `<li style="margin:0 0 3px;">${esc(x)}</li>`).join('')}</ul>
            ${(plan.plan.unit.french || []).length ? `<div style="margin-top:6px;font-size:12px;">🇫🇷 ${plan.plan.unit.french.slice(0, 6).map(p => `${esc(p[0])} → <strong lang="fr">${esc(p[1])}</strong>`).join(' · ')}</div>` : ''}
          </div>` : ''}
      </div>
    `;
  }

  function parentDayCard(day, activities) {
    return `
      <div style="background:#F9FAFB;border-radius:8px;padding:10px;">
        <div style="font-weight:700;font-size:12px;color:#6B7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">${DAY_LABELS[day]}</div>
        ${activities.length === 0
          ? '<div style="font-size:12px;color:#D1D5DB;">—</div>'
          : activities.map(a => {
              const dom = DOMAINS.find(d => d.v === a.domain);
              const bg = dom ? dom.c + '40' : '#FFF';
              return `<div style="background:${bg};border-radius:6px;padding:6px 8px;margin-bottom:4px;font-size:12px;">
                ${picImg(a, 'lp-ppic', a.title || '')}
                ${picUrl(a) && a.image_credit_required && a.image_credit ? `<div class="lp-credit">Picture: ${esc(a.image_credit)}</div>` : ''}
                ${a.time ? `<span style="color:#6B7280;white-space:nowrap;">${esc(fmtTime(a.time))}</span> ` : ''}
                <strong>${esc(a.title||'')}</strong>
              </div>`;
            }).join('')
        }
      </div>
    `;
  }

  /* "Oct 19 – 23, 2026" for the week on screen; "Oct 19" for one of its days. Local noon so a
     UTC offset never shifts the date. */
  function weekRange(monday) {
    try {
      const a = new Date(monday + 'T12:00:00'), b = new Date(a); b.setDate(a.getDate() + 4);
      const m = (d) => d.toLocaleDateString(undefined, { month: 'short' });
      return m(a) + ' ' + a.getDate() + ' – ' + (m(b) !== m(a) ? m(b) + ' ' : '') + b.getDate() + ', ' + b.getFullYear();
    } catch (e) { return monday; }
  }
  function dayDate(day) {
    try {
      const d = new Date(activeWeek + 'T12:00:00'); d.setDate(d.getDate() + DAYS.indexOf(day));
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    } catch (e) { return ''; }
  }
  function lpCss() {
    picCss();
    if (document.getElementById('kt-lp-css')) return;
    const st = document.createElement('style'); st.id = 'kt-lp-css';
    st.textContent = ''
      + '.lp-wrap{padding:18px 20px 28px;max-width:1800px;box-sizing:border-box}'
      + '.lp-bar{background:#fff;border:1px solid #E6EBF1;border-radius:14px;padding:12px 14px;box-shadow:0 1px 3px rgba(15,23,42,.04);margin-bottom:12px;display:flex;flex-direction:column;gap:10px}'
      + '.lp-row{display:flex;flex-wrap:wrap;gap:10px 12px;align-items:flex-end}'
      + '.lp-field{display:flex;flex-direction:column;gap:4px;min-width:0}.lp-field>span{font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B}'
      + '.lp-grow{flex:0 1 300px}'
      // #appMain: the phone rule for every select (padding 11px) outranks a class and clipped the text.
      + '#appMain .lp-field select,.lp-field select{height:40px !important;min-height:40px !important;padding:0 30px 0 11px !important;line-height:normal !important;border:1px solid #CBD5E1;border-radius:9px;font-size:15px !important;background-color:#fff;max-width:100%;width:auto}'
      + '.lp-week{display:flex;align-items:center;gap:4px;background:#F1F5F9;border-radius:10px;padding:3px;height:38px;box-sizing:border-box}'
      + '.lp-week>button{width:32px;height:32px;min-height:0 !important;border:0;border-radius:8px;background:#fff;color:#1F6080;font-size:18px;font-weight:800;cursor:pointer;padding:0 !important;line-height:1}'
      + '.lp-week-label{position:relative;font-weight:800;font-size:13.5px;color:#0F172A;padding:0 10px;white-space:nowrap;cursor:pointer}'
      + '.lp-week-label input{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer;border:0;padding:0}'
      + '.lp-row-actions{justify-content:space-between;align-items:center;border-top:1px solid #F1F5F9;padding-top:10px}'
      + '.lp-state{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.lp-count{font-size:12.5px;color:#64748B;font-weight:600}'
      + '.lp-chip{display:inline-flex;align-items:center;font-size:11.5px;font-weight:800;padding:4px 10px;border-radius:999px}'
      + '.lp-chip-pub{background:#DCFCE7;color:#166534}.lp-chip-draft{background:#FEF3C7;color:#92400E}.lp-chip-none{background:#F1F5F9;color:#475569}'
      + '.lp-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.lp-tools,.lp-commit{display:flex;flex-wrap:wrap;gap:8px;align-items:center}'
      + '.lp-btn{height:38px !important;min-height:38px !important;padding:0 14px !important;border-radius:9px;font-size:13.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;box-sizing:border-box;line-height:1}'
      + '.lp-btn-sm{height:34px !important;min-height:34px !important;padding:0 11px !important;font-size:12.5px}'
      + '.lp-btn-primary{background:linear-gradient(135deg,#1F6080,#2c7894);color:#fff;border:0;box-shadow:0 6px 14px -8px rgba(31,96,128,.85)}'
      + '.lp-btn-ghost{background:#fff;color:#1F6080;border:1px solid #CFE3EB}'
      + '.lp-seg{display:inline-flex;background:#F1F5F9;border-radius:9px;padding:3px;gap:2px}'
      + '.lp-seg button{height:32px;min-height:0 !important;padding:0 12px !important;border:0;border-radius:7px;background:transparent;font-size:12.5px;font-weight:800;color:#475569;cursor:pointer}'
      + '.lp-seg button.on{background:#fff;color:#0F172A;box-shadow:0 1px 3px rgba(15,23,42,.12)}'
      + '.lp-note{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#EAF3F6;border:1px solid #CFE3EB;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#16526E}'
      + '.lp-note-warn{background:#FFFBEB;border-color:#FCD34D;color:#92400E}'
      + '.lp-theme{background:#fff;border:1px solid #E6EBF1;border-radius:14px;padding:12px 16px;margin-bottom:12px}'
      + '.lp-theme-lbl{font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin-bottom:3px}'
      + '.lp-theme-txt{font-size:18px;font-weight:800;color:#0F172A}.lp-muted{color:#94A3B8 !important;font-weight:600 !important}'
      + '.lp-theme input{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #CBD5E1;border-radius:9px;font-size:16px;font-weight:700;color:#0F172A}'
      + '.lp-daytabs{display:none;gap:5px;margin-bottom:10px}'
      + '.lp-daytabs button{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:1px;padding:7px 2px !important;min-height:0 !important;height:auto !important;border:1px solid #E2E8F0;border-radius:11px;background:#fff;cursor:pointer;position:relative}'
      + '.lp-daytabs b{font-size:13px;color:#0F172A}.lp-daytabs small{font-size:10.5px;color:#64748B}'
      + '.lp-daytabs i[hidden]{display:none}.lp-daytabs i{position:absolute;top:3px;right:3px;font-style:normal;font-size:10px;font-weight:800;background:#1F6080;color:#fff;border-radius:999px;min-width:16px;padding:1px 4px;line-height:14px}'
      + '.lp-daytabs button.on{background:#1F6080;border-color:#1F6080}.lp-daytabs button.on b,.lp-daytabs button.on small{color:#fff}.lp-daytabs button.on i{background:#F59E0B}'
      + '.lp-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}'
      + '.lp-day{background:#fff;border:1px solid #E6EBF1;border-top:4px solid var(--dc);border-radius:12px;padding:10px;min-height:220px;display:flex;flex-direction:column;gap:7px}'
      + '.lp-day.lp-today{background:#FFFDF5;box-shadow:0 0 0 2px #FDE68A}'
      + '.lp-day header{display:flex;align-items:baseline;gap:6px;margin-bottom:2px}'
      + '.lp-dname{font-weight:900;font-size:14px;color:var(--dc);text-transform:uppercase;letter-spacing:.8px}.lp-ddate{font-size:12px;color:#64748B;font-weight:600}'
      + '.lp-dtoday{font-size:10px;font-weight:800;background:#F59E0B;color:#fff;padding:1px 7px;border-radius:999px}'
      + '.lp-dcount{margin-left:auto;font-size:11px;font-weight:800;color:#94A3B8}'
      + '.lp-empty{font-size:12.5px;color:#94A3B8;text-align:center;padding:14px 6px;border:1px dashed #E2E8F0;border-radius:9px}'
      + '.lp-act{background:color-mix(in srgb,var(--ac) 16%,#fff);--kt-edge:var(--ac);border-radius:9px;padding:8px 10px;display:flex;flex-direction:column;gap:5px}'
      + '.lp-act.kt-activity-tap{cursor:pointer}.lp-act.kt-activity-tap:hover{box-shadow:0 2px 8px rgba(15,23,42,.08)}'
      + '.lp-act-top{display:flex;align-items:flex-start;gap:7px}'
      + '.lp-time{flex:0 0 auto;font-size:11px;font-weight:800;color:#1F6080;background:#fff;border:1px solid #CFE3EB;border-radius:6px;padding:1px 6px;font-variant-numeric:tabular-nums;white-space:nowrap}'
      + '.lp-title{font-size:13px;font-weight:800;color:#0F172A;line-height:1.3;overflow-wrap:anywhere}'
      + '.lp-notes{font-size:12px;color:#475569;line-height:1.4;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}'
      + '.lp-dom{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#334155;background:#fff;border:1px solid #E2E8F0;border-radius:999px;padding:3px 10px 3px 8px}'
      + '.lp-dom::before{content:"";width:9px;height:9px;border-radius:50%;background:var(--c)}'
      + '.lp-dom-sm{align-self:flex-start;font-size:10.5px;padding:1px 8px 1px 6px}'
      + '.lp-add{margin-top:auto;width:100%;height:38px !important;min-height:0 !important;background:#F0F9FF !important;color:#1F6080 !important;border:1.5px dashed #93C5FD !important;border-radius:10px !important;font-size:13px !important;font-weight:800 !important;cursor:pointer;box-shadow:none !important}'
      + '.lp-status{margin-top:12px;font-size:14px;min-height:20px}'
      + '.lp-legend{margin-top:16px;display:flex;flex-wrap:wrap;gap:6px;align-items:center}.lp-legend-lbl{font-size:11px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#64748B;margin-right:4px}'
      + '@media (max-width:1100px){.lp-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}'
      + '@media (max-width:768px){.lp-wrap{padding:12px 4px 24px}.lp-daytabs{display:flex}.lp-grid{grid-template-columns:1fr}.lp-grid>.lp-day:not(.on){display:none}'
      + '.lp-day{min-height:0}'
      // What + which week: two equal columns, the week across both.
      + '.lp-bar>.lp-row:first-child{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}'
      + '.lp-bar>.lp-row:first-child>.lp-field{min-width:0}.lp-bar>.lp-row:first-child>.lp-field:only-of-type,.lp-field:has(.lp-week),#kt-lp-thisweek{grid-column:1 / -1}'
      + '#appMain .lp-field select,.lp-field select{width:100% !important;min-width:0 !important;max-width:100% !important}'
      + '.lp-week{width:100%}.lp-week-label{flex:1;text-align:center}'
      + '.lp-row-actions{flex-direction:column;align-items:stretch}.lp-actions{flex-direction:column;align-items:stretch}'
      + '.lp-seg{width:100%;box-sizing:border-box}.lp-seg button{flex:1 1 0}'
      + '.lp-commit{flex-wrap:nowrap}.lp-tools{flex-wrap:wrap}'
      + '.lp-tools>.lp-btn{flex:1 1 auto;justify-content:center;padding:0 10px !important}.lp-commit>.lp-btn{flex:1 1 0;min-width:0;justify-content:center;padding:0 8px !important}'
      + '.lp-actions>.lp-btn-primary{justify-content:center}.lp-commit>.lp-btn-primary{flex:2 1 0}}'
      // A narrow phone: the two pickers one under the other, so the room name is not cut off.
      + '@media (max-width:400px){.lp-bar>.lp-row:first-child{grid-template-columns:1fr}}';
    document.head.appendChild(st);
  }

  /* Pictures (2026-11-02). Their own style element, so a page still holding the previous kt-lp-css gets them too. */
  function picCss() {
    if (document.getElementById('kt-lp-pic-css')) return;
    const st = document.createElement('style'); st.id = 'kt-lp-pic-css';
    st.textContent = ''
      // A short banner across the top of the card: a thumbnail beside the title squeezed it into a 3-letter column.
      + '.lp-act>.lp-pic{display:block;width:100%;height:auto;aspect-ratio:16/9;max-height:118px;object-fit:cover;border-radius:7px;background:#F1F5F9;cursor:zoom-in}'
      + '.kt-activity-tap .lp-pic{cursor:pointer}'
      + '.lp-theme-main{display:flex;gap:14px;align-items:center}.lp-theme-body{flex:1 1 auto;min-width:0}'
      + '.lp-cover{flex:0 0 112px}.lp-cover-img{display:block;width:112px;height:84px;object-fit:cover;border-radius:10px;background:#F1F5F9;cursor:zoom-in}'
      + '.lp-theme-edit{display:flex;gap:8px;align-items:center}.lp-theme-edit input{flex:1 1 auto;min-width:0}'
      + '.lp-picopt{margin-top:12px;font-size:12.5px;color:#374151}.lp-picopt label{display:inline-flex;align-items:center;gap:8px;font-weight:600;cursor:pointer}'
      + '.lp-picopt input{width:16px;height:16px}.lp-picopt div{font-size:11.5px;color:#94A3B8;margin-top:3px}'
      + '.lp-ph-lbl{display:block;font-size:12px;font-weight:700;color:#475569;margin:14px 0 6px}'
      + '.lp-ph{display:flex;gap:12px;align-items:center}'
      + '.lp-ph-prev{flex:0 0 96px;width:96px;height:72px;border-radius:10px;background:#F1F5F9;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#94A3B8;font-size:11.5px}'
      + '.lp-ph-prev img{width:100%;height:100%;object-fit:cover;display:block}'
      + '.lp-ph-btns{display:flex;flex-wrap:wrap;gap:6px}'
      + '.lp-ph-btns button,.lp-ph-search button{height:32px;min-height:0 !important;padding:0 11px !important;border:1px solid #CBD5E1;background:#fff;color:#1F6080;border-radius:8px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer}'
      + '.lp-ph-btns button[hidden]{display:none}.lp-ph-btns .lp-ph-rm{border-color:#FCA5A5;color:#B91C1C}'
      + '.lp-ph-btns button[aria-busy=true]{opacity:.6;cursor:progress}'
      + '.lp-ph-note{font-size:11.5px;color:#64748B;margin-top:6px;min-height:14px;overflow-wrap:anywhere}'
      + '.lp-ph-box{margin-top:8px;border:1px solid #E2E8F0;border-radius:10px;padding:8px}.lp-ph-box[hidden]{display:none}'
      + '.lp-ph-search{display:flex;gap:6px;margin-bottom:8px}.lp-ph-search input{flex:1 1 auto;min-width:0;height:32px;box-sizing:border-box;padding:0 10px;border:1px solid #CBD5E1;border-radius:8px;font:inherit;font-size:13px}'
      + '.lp-ph-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:6px;max-height:240px;overflow:auto}'
      + '.lp-ph-cand{padding:0 !important;min-height:0 !important;border:2px solid transparent;border-radius:8px;background:#F1F5F9;cursor:pointer;aspect-ratio:4/3;overflow:hidden}'
      + '.lp-ph-cand:hover,.lp-ph-cand:focus-visible{border-color:#1F6080}.lp-ph-cand img{width:100%;height:100%;object-fit:cover;display:block}'
      + '.lp-ph-cand[aria-busy=true]{opacity:.5}'
      + '.lp-ph-msg{grid-column:1 / -1;font-size:12px;color:#64748B;padding:6px 2px}.lp-ph-src{font-size:10.5px;color:#94A3B8;margin-top:6px}'
      + '.lp-pcover{margin:0 0 10px}.lp-pcover-img{display:block;width:100%;max-height:220px;object-fit:cover;border-radius:12px;background:#F1F5F9;cursor:zoom-in}'
      + '.lp-ppic{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:6px;margin-bottom:5px;background:#F1F5F9;cursor:zoom-in}'
      + '.lp-credit{font-size:10px;color:#94A3B8;margin:2px 0 4px;line-height:1.3;overflow-wrap:anywhere}'
      + '@media (max-width:768px){.lp-act>.lp-pic{max-height:170px}.lp-cover{flex-basis:88px}.lp-cover-img{width:88px;height:66px}'
      + '.lp-pcover-img{max-height:180px}}';
    document.head.appendChild(st);
  }

  function selectStyle() { return 'padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;background:white;'; }
  function navBtnStyle() { return 'background:white;border:1px solid #D1D5DB;width:36px;height:36px;border-radius:8px;font-size:18px;font-weight:700;cursor:pointer;color:#6B7280;'; }

  function render(container) {
    const role = getRole();
    if (role === 'guardian') renderParent(container);
    else renderProvider(container);
  }

  // Point the planner at a specific scope/target/week BEFORE navigating to it.
  // "Use template" saves into one room-or-centre + week, then sends the user here;
  // without this the planner opened on its remembered scope (default: room scope,
  // first room, current week) and showed an empty grid — the plan looked "not
  // saved" even though it was. Called by screen-v22p57.js right before the hash nav.
  function focus(opts) {
    opts = opts || {};
    if (opts.scope === 'centre' || opts.scope === 'room') activeScope = opts.scope;
    if (opts.roomId != null) activeRoomId = parseInt(opts.roomId, 10);
    if (opts.centreId != null) activeCentreId = parseInt(opts.centreId, 10);
    if (opts.week) activeWeek = mondayOf(opts.week);
  }

  window.KT = window.KT || {};
  window.KT.LessonPlans = { render, focus };
})(window);
