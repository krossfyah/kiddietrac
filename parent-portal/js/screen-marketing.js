/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v22p34 — Marketing campaigns
   Hash: #marketing-campaigns
   Available to centre_director and agency_admin (and platform_admin
   when scoped to an active agency).
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  if (!window.KT) return;
  var KT = window.KT;
  var Api = KT.Api;
  var Dom = KT.Dom;
  var Shell = KT.Shell;

  var ASSET_BASE = ((KT.Api && KT.Api.base) || 'https://api.kiddietrac.com/api/v1').replace(/\/api\/v1\/?$/, '');
  function absUrl(p) {
    if (!p) return '';
    if (/^https?:\/\//i.test(p)) return p;
    return ASSET_BASE + p;
  }
  function esc(s) { return s == null ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return d; } }
  function normUrl(u) { if (!u) return ''; return /^https?:\/\//i.test(u) ? u : 'https://' + u; }
  function activeAgencyId() {
    try { var a = parseInt(sessionStorage.getItem('kt_active_agency_id'), 10); if (a) return a; } catch (e) {}
    try { var u = JSON.parse(sessionStorage.getItem('kt_user') || '{}'); return u.agency_id || null; } catch (e) { return null; }
  }

  // Branding + plan entitlements for the campaign frame — fetched once, cached.
  // White-label is NOT a per-campaign choice: it is granted by the agency's
  // tier/package (the `white_label` feature flag). When the plan includes it,
  // the agency logo + details are applied automatically; otherwise campaigns
  // use a standard header.
  var _featCache = null;
  function loadFeatures() {
    if (_featCache) return Promise.resolve(_featCache);
    var id = activeAgencyId();
    if (!id) return Promise.resolve({ branding: {}, whiteLabel: false });
    return Api.get('/admin/agencies/' + id + '/features')
      .then(function (d) {
        _featCache = {
          branding: (d && d.branding) || {},
          whiteLabel: !!(d && d.flags && d.flags.white_label),
        };
        return _featCache;
      })
      .catch(function () { return { branding: {}, whiteLabel: false }; });
  }

  // ── List view ──────────────────────────────────────────────────────
  function render(container) {
    Dom.clear(container);
    var wrap = Dom.el('div', { style: 'padding:24px;max-width:1800px;margin:0 auto;' });
    container.appendChild(wrap);

    var hero = Dom.el('div', { class: 'kt-hero', style: 'background:linear-gradient(135deg,#FF8A65 0%,#7C3AED 60%,#1F6080 100%);' });
    hero.innerHTML = '<div class="kt-hero-greet">📣 MARKETING</div><h1>Campaigns</h1><div class="kt-hero-sub">Newsletters, open-house invites, promotions. Compose, preview, schedule and send to the families you choose.</div>';
    wrap.appendChild(hero);

    var bar = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin:18px 0;' });
    bar.appendChild(Dom.el('div', { style: 'color:#6B7280;font-size:13px;' }, 'Drafts, scheduled, and recently sent'));
    var newBtn = Dom.el('button', { style: 'background:#1F6080;color:white;border:none;padding:10px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;' }, '+ New campaign');
    newBtn.addEventListener('click', function () { openComposer(null, container); });
    bar.appendChild(newBtn);
    wrap.appendChild(bar);

    var listWrap = Dom.el('div', { 'data-kt-list': '1', style: 'background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;' });
    wrap.appendChild(listWrap);
    listWrap.appendChild(Dom.el('div', { style: 'padding:40px;text-align:center;color:#64748B;' }, 'Loading…'));

    Api.get('/marketing/campaigns').then(function (data) {
      Dom.clear(listWrap);
      if (!data.campaigns || !data.campaigns.length) {
        listWrap.appendChild(Dom.el('div', { style: 'padding:48px;text-align:center;color:#6B7280;' }, 'No campaigns yet. Click + New campaign to compose your first.'));
        return;
      }
      data.campaigns.forEach(function (c) { listWrap.appendChild(renderRow(c, container)); });
      // This list renders async (no hashchange), so nudge the kebab sweep directly.
      if (window.KT && typeof KT.sweepRowActions === 'function') setTimeout(KT.sweepRowActions, 0);
    }).catch(function (e) {
      Dom.clear(listWrap);
      listWrap.appendChild(Dom.el('div', { style: 'padding:24px;color:#DC2626;' }, 'Could not load: ' + (e.message || 'error')));
    });
  }

  function renderRow(c, container) {
    var row = Dom.el('div', { style: 'display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #F3F4F6;cursor:pointer;' });
    row.addEventListener('mouseenter', function () { row.style.background = '#FAFBFC'; });
    row.addEventListener('mouseleave', function () { row.style.background = 'white'; });

    if (c.hero_image_url) {
      var thumb = Dom.el('img', { src: absUrl(c.hero_image_url), style: 'width:56px;height:56px;border-radius:8px;object-fit:cover;background:#F3F4F6;flex-shrink:0;' });
      row.appendChild(thumb);
    } else {
      row.appendChild(Dom.el('div', { style: 'width:56px;height:56px;border-radius:8px;background:#F3F4F6;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;' }, '📣'));
    }

    var body = Dom.el('div', { style: 'flex:1;min-width:0;' });
    body.appendChild(Dom.el('div', { style: 'font-weight:700;font-size:15px;color:#111827;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, c.title));
    var meta = (c.channel || 'in_portal').replace('_', ' ') + ' · ' + (c.audience || '').replace('_', ' ');
    if (c.scheduled_for) meta += ' · scheduled for ' + fmtDate(c.scheduled_for);
    else if (c.sent_at) meta += ' · sent ' + fmtDate(c.sent_at) + (c.recipient_count != null ? ' · ' + c.recipient_count + ' recipients' : '');
    body.appendChild(Dom.el('div', { style: 'font-size:12px;color:#6B7280;' }, meta));
    row.appendChild(body);

    row.appendChild(statusPill(c.status));

    // Trailing action bar (Edit / Delete) — kt-row-actions.js collapses it into the
    // standard ⋮ kebab because the list is [data-kt-list] and this is the row's last
    // child holding only action buttons. Row click still opens the composer.
    var actionsBar = Dom.el('div', { style: 'display:flex;gap:6px;flex-shrink:0;' });
    var editBtn = Dom.el('button', { type: 'button', class: 'kt-act-icon kt-act-edit kt-icon-tip', 'data-kttip': 'Edit', 'aria-label': 'Edit' }, '✏️');
    editBtn.addEventListener('click', function (e) { e.stopPropagation(); openComposer(c, container); });
    var delBtn = Dom.el('button', { type: 'button', class: 'kt-act-icon kt-act-danger kt-icon-tip', 'data-kttip': 'Delete', 'aria-label': 'Delete' }, '🗑️');
    delBtn.addEventListener('click', async function (e) {
      e.stopPropagation();
      if (!await KT.confirm('Delete this campaign? This cannot be undone.')) return;
      Api.delete('/marketing/campaigns/' + c.id).then(function () { render(container); });
    });
    actionsBar.appendChild(editBtn);
    actionsBar.appendChild(delBtn);
    row.appendChild(actionsBar);

    row.addEventListener('click', function () { openComposer(c, container); });
    return row;
  }

  function statusPill(s) {
    var map = {
      draft:     { bg: '#F3F4F6', fg: '#6B7280', t: 'DRAFT' },
      scheduled: { bg: '#FEF3C7', fg: '#92400E', t: 'SCHEDULED' },
      sending:   { bg: '#DBEAFE', fg: '#1E40AF', t: 'SENDING' },
      sent:      { bg: '#DCFCE7', fg: '#166534', t: 'SENT' },
      archived:  { bg: '#FEE2E2', fg: '#991B1B', t: 'ARCHIVED' },
    };
    var m = map[s] || { bg: '#F3F4F6', fg: '#6B7280', t: (s || '').toUpperCase() };
    var span = Dom.el('span', { style: 'padding:3px 10px;border-radius:999px;background:' + m.bg + ';color:' + m.fg + ';font-size:10px;font-weight:700;letter-spacing:0.5px;flex-shrink:0;' }, m.t);
    return span;
  }

  // ── Composer ───────────────────────────────────────────────────────
  function openComposer(existing, container) {
    /* ONE COMPOSER, AND IT DOES NOT OUTLIVE THE SCREEN.

       The overlay is appended to <body>, not to #appMain, so navigating away left it
       sitting on top of whatever you went to — and pressing "+ New campaign" again stacked
       a second one over the first, with the older copy still holding its own state
       underneath. Found while testing the asset pickers: two live composers, eight picker
       cards, and typing going into whichever happened to be on top.

       So: any stale composer is removed before a new one opens, and the overlay tears
       itself down on the next hash change. Marked with an id so both checks have something
       to find. (Anthony, 2026-09-10) */
    var stale = document.getElementById('kt-campaign-composer');
    if (stale) { stale.remove(); }

    var overlay = Dom.el('div', { id: 'kt-campaign-composer', style: 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;overflow:auto;' });

    /* Leaving the screen closes it. Registered once per composer and removed with it, so
       a closed composer leaves no listener behind. */
    var closeOnNav = function () { overlay.remove(); };
    window.addEventListener('hashchange', closeOnNav);
    var _origRemove = overlay.remove.bind(overlay);
    overlay.remove = function () {
      window.removeEventListener('hashchange', closeOnNav);
      _origRemove();
    };
    var modal = Dom.el('div', { style: 'background:white;border-radius:16px;max-width:920px;width:100%;max-height:calc(100vh - 48px);overflow-y:auto;box-shadow:0 12px 36px rgba(0,0,0,.25);' });
    overlay.appendChild(modal);

    var header = Dom.el('div', { style: 'padding:18px 24px;border-bottom:1px solid #E5E7EB;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:2;' });
    header.appendChild(Dom.el('h2', { style: 'margin:0;font-size:18px;' }, existing ? 'Edit campaign' : 'New campaign'));
    var closeBtn = Dom.el('button', { style: 'background:transparent;border:none;font-size:22px;color:#6B7280;cursor:pointer;line-height:1;padding:4px 10px;' }, '×');
    closeBtn.addEventListener('click', function () { overlay.remove(); });
    header.appendChild(closeBtn);
    modal.appendChild(header);

    var body = Dom.el('div', { style: 'padding:20px 24px;' });
    modal.appendChild(body);

    // Title
    body.appendChild(labelEl('Campaign title'));
    var titleIn = Dom.el('input', { type: 'text', placeholder: 'Spring open house · April newsletter · etc.', value: existing ? existing.title : '', style: inputStyle() });
    body.appendChild(titleIn);

    // Subject (email)
    body.appendChild(labelEl('Email subject (used when channel includes email)'));
    var subjectIn = Dom.el('input', { type: 'text', placeholder: 'Save the date: our spring open house', value: existing ? (existing.subject || '') : '', style: inputStyle() });
    body.appendChild(subjectIn);

    // Audience + channel row
    var row2 = Dom.el('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;' });
    var audWrap = Dom.el('div');
    audWrap.appendChild(labelEl('Audience'));
    var audSel = Dom.el('select', { style: inputStyle() });
    [['all_families','All families'],['active_families','Currently enrolled'],['waitlist','Waitlist'],['prospects','Prospects (no active child)'],['staff','Staff only']].forEach(function (o) {
      var opt = Dom.el('option', { value: o[0] }, o[1]);
      if (existing && existing.audience === o[0]) opt.selected = true;
      audSel.appendChild(opt);
    });
    audWrap.appendChild(audSel);
    row2.appendChild(audWrap);

    var chWrap = Dom.el('div');
    chWrap.appendChild(labelEl('Channel'));
    var chSel = Dom.el('select', { style: inputStyle() });
    [['in_portal','In-portal (announcements)'],['email','Email (via scheduler)'],['both','Both']].forEach(function (o) {
      var opt = Dom.el('option', { value: o[0] }, o[1]);
      if (existing && existing.channel === o[0]) opt.selected = true;
      chSel.appendChild(opt);
    });
    chWrap.appendChild(chSel);
    row2.appendChild(chWrap);
    body.appendChild(row2);

    /* Declared before the first picker, not after.

       This used to sit further down, beside the header and footer selects that were its
       only readers. Moving the hero onto the same picker made it the FIRST reader — and
       `var` hoists the declaration without the assignment, so assetState was undefined
       when the hero picker painted itself: "Cannot read properties of undefined (reading
       'list')", and the whole composer failed to open. */
    var assetState = { header: null, footer: null, list: [] };

    /* HERO IMAGE — KEPT, NOT THROWN AWAY.

       This used to post straight to /marketing/images, which returns a URL and keeps no
       record: the picture existed, nothing listed it, and the next campaign that wanted
       the same banner had to go and find the original file again. A hero is the same kind
       of thing as a header — an image an agency reuses — so it is now saved as a marketing
       asset and picked from the same library, with the picture itself on screen rather
       than a filename in a dropdown. (Anthony, 2026-09-10) */
    var heroUrl = existing ? (existing.hero_image_url || '') : '';
    var heroPicker = assetPicker('hero', 'Hero image (optional)',
      'The picture at the top of the message. Uploads are kept — pick one you have used before, or add a new one.',
      {
        selectedUrl: heroUrl,
        onPick: function (a) { heroUrl = a ? (a.image_url || '') : ''; },
      });
    body.appendChild(heroPicker);

    // White-label status — driven by the agency's plan/package, NOT a manual
    // choice. The "Powered by Kiddietrac" footer (with privacy/terms) is always
    // added. We resolve the entitlement when the composer opens (below).
    var wlState = { on: false };
    var wlNote = Dom.el('div', { style: 'display:flex;align-items:center;gap:10px;padding:11px 12px;background:#F9FAFB;border:1px solid #EEF0F2;border-radius:8px;margin-bottom:14px;font-size:13px;color:#6B7280;' }, 'Checking your plan…');
    body.appendChild(wlNote);
    loadFeatures().then(function (f) {
      wlState.on = f.whiteLabel;
      if (f.whiteLabel) {
        wlNote.style.background = '#ECFDF5'; wlNote.style.borderColor = '#A7F3D0'; wlNote.style.color = '#065F46';
        wlNote.innerHTML = '✓ <strong>White-label branding is included in your plan.</strong> Your agency logo &amp; details appear at the top of every campaign. <span style="opacity:.8;">Edit them under Settings → Branding.</span>';
      } else {
        wlNote.innerHTML = 'Campaigns use a standard header. <strong>White-label branding</strong> (your logo &amp; details at the top) is included on the Growth plan and above.';
      }
    });

    // Rich-text editor
    body.appendChild(labelEl('Body'));
    /* Header and footer pickers. The saved blocks are fetched once and both selects
       are filled from the same list, so uploading a header immediately shows up in the
       footer list too if that is where it belongs. */

    /* ONE PICKER FOR HERO, HEADER AND FOOTER.

       Header and footer were a <select> of names with a preview underneath, and the hero
       was a lone upload button. Three different answers to the same question, and none of
       them let you SEE what you were choosing between — which is the whole difficulty with
       a banner. This shows the library as cards: the image itself, its name, click to
       choose. A footer that is words rather than a picture shows its text in the card, so
       the two kinds sit in one list without one of them being invisible.

       Reopening a campaign restores what it was saved with: `opts.selectedId` for the
       header and footer, `opts.selectedUrl` for the hero — which is all the campaign row
       keeps for it, since a hero is stored as a URL rather than an asset id.
       (Anthony, 2026-09-10) */
    function assetPicker(kind, label, hint, opts) {
        opts = opts || {};
        var wrap = Dom.el('div', { style: 'margin-top:14px;margin-bottom:4px;' });
        wrap.appendChild(labelEl(label));
        if (hint) {
            wrap.appendChild(Dom.el('div', {
                style: 'font-size:12px;color:#64748B;margin:-4px 0 8px;',
            }, hint));
        }

        var strip = Dom.el('div', {
            style: 'display:flex;gap:10px;overflow-x:auto;padding:2px 2px 8px;align-items:stretch;',
            'data-kt-scroll': '1',
        });
        wrap.appendChild(strip);

        var upBtn = Dom.el('button', { type: 'button', class: 'kt-btn kt-btn-secondary kt-btn-sm' },
            kind === 'hero' ? '＋ Upload a hero image' : '＋ Upload new');
        upBtn.setAttribute('data-kt-no-icon', '1');
        wrap.appendChild(upBtn);

        var chosenId = null;

        function card(a) {
            var isNone = !a;
            var on = isNone ? (chosenId === null) : String(a.id) === String(chosenId);
            var c = Dom.el('div', {
                style: 'flex:0 0 auto;width:150px;border:2px solid ' + (on ? '#1F6080' : '#E2E8F0') + ';'
                    + 'border-radius:10px;overflow:hidden;cursor:pointer;background:#fff;'
                    + 'box-shadow:' + (on ? '0 0 0 3px rgba(31,96,128,.12)' : 'none') + ';',
            });

            var thumb = Dom.el('div', {
                style: 'height:76px;background:#F3F4F6;background-size:cover;background-position:center;'
                    + 'display:flex;align-items:center;justify-content:center;color:#94A3B8;font-size:12px;'
                    + 'text-align:center;padding:6px;box-sizing:border-box;overflow:hidden;',
            });
            if (isNone) {
                thumb.textContent = 'None';
            } else if (a.image_url) {
                thumb.style.backgroundImage = 'url(' + absUrl(a.image_url) + ')';
            } else {
                /* Text-only block: show the words, because "no image" is not the same as
                   "nothing here" and a blank tile reads as broken. */
                thumb.style.fontSize = '11px';
                thumb.style.color = '#475569';
                thumb.textContent = String(a.html || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 90) || 'Text block';
            }
            c.appendChild(thumb);

            c.appendChild(Dom.el('div', {
                style: 'padding:6px 8px;font-size:12px;font-weight:700;color:' + (on ? '#1F6080' : '#334155') + ';'
                    + 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;',
            }, isNone ? '— none —' : (a.name || 'Untitled')));

            c.addEventListener('click', function () {
                chosenId = isNone ? null : a.id;
                apply();
                paint();
            });
            return c;
        }

        /* Tell the outside world what is chosen. assetState is what the save and the
           preview read; onPick is for the hero, which is stored on the campaign as a URL
           rather than an id. */
        function apply() {
            var a = mine().filter(function (x) { return String(x.id) === String(chosenId); })[0] || null;
            if (kind === 'hero') {
                if (typeof opts.onPick === 'function') { opts.onPick(a); }
            } else {
                assetState[kind] = a ? a.id : null;
            }
        }

        function mine() {
            return assetState.list.filter(function (a) { return a.kind === kind; });
        }

        function paint() {
            strip.innerHTML = '';
            strip.appendChild(card(null));
            var list = mine();
            list.forEach(function (a) { strip.appendChild(card(a)); });
            if (!list.length) {
                strip.appendChild(Dom.el('div', {
                    style: 'flex:0 0 auto;align-self:center;font-size:12.5px;color:#94A3B8;padding-left:4px;',
                }, 'Nothing saved yet — upload one and it stays here for next time.'));
            }
        }

        /* Restore what this campaign was saved with. By id for header/footer, by URL for
           the hero — which is all the campaign row keeps for it. */
        function restore() {
            var list = mine();
            if (kind === 'hero') {
                if (opts.selectedUrl) {
                    var hit = list.filter(function (a) {
                        return a.image_url && absUrl(a.image_url) === absUrl(opts.selectedUrl);
                    })[0];
                    chosenId = hit ? hit.id : null;
                }
            } else if (opts.selectedId) {
                chosenId = String(opts.selectedId);
            }
            apply();
            paint();
        }

        upBtn.addEventListener('click', function () {
            openAssetUpload(kind, function (created) {
                if (created && created.id) { chosenId = created.id; }
                apply();
                paint();
            });
        });

        wrap._paint = paint;
        wrap._restore = restore;
        paint();
        return wrap;
    }


    var KIND_WORD = { header: 'header', footer: 'footer', hero: 'hero image' };

    function openAssetUpload(kind, onSaved) {
        var m = document.createElement('div');
        m.setAttribute('data-no-modal-guard', '1');
        m.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;'
            + 'display:flex;align-items:center;justify-content:center;padding:20px;';
        m.innerHTML = '<div style="background:#fff;padding:26px;border-radius:14px;max-width:520px;width:100%;'
            + 'max-height:calc(100vh - 40px);overflow-y:auto;">'
            + '<h3 style="margin:0 0 14px;">New ' + (KIND_WORD[kind] || 'header') + '</h3>'
            + '<label style="display:block;font-size:13px;font-weight:600;margin:10px 0 4px;">Name it</label>'
            + '<input id="ma-name" placeholder="e.g. Spring 2026 banner" '
            + 'style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;box-sizing:border-box;">'
            + '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Image</label>'
            + '<input id="ma-file" type="file" accept="image/jpeg,image/png,image/gif" '
            + 'style="width:100%;padding:9px;border:1px dashed #CBD5E1;border-radius:8px;font-size:13px;'
            + 'background:#F8FAFC;box-sizing:border-box;">'
            + '<div style="font-size:12px;color:#64748B;margin-top:4px;">'
            + 'JPEG, PNG or GIF. Wide images are resized to 600px for email.</div>'
            /* A hero is a picture by definition — offering it a text box would invite
               somebody to save a "hero" with nothing to show. Headers and footers are
               often words (an address, unsubscribe wording), so they keep it. */
            + (kind === 'hero' ? ''
                : '<label style="display:block;font-size:13px;font-weight:600;margin:14px 0 4px;">Or text (optional)</label>'
                  + '<textarea id="ma-html" rows="3" placeholder="Address, unsubscribe wording, a closing line…" '
                  + 'style="width:100%;padding:9px;border:1px solid #E2E8F0;border-radius:8px;font-family:inherit;'
                  + 'box-sizing:border-box;"></textarea>')
            + '<div id="ma-msg" style="font-size:13px;margin-top:12px;min-height:18px;"></div>'
            + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">'
            + '<button id="ma-cancel" class="kt-btn kt-btn-secondary" type="button">Cancel</button>'
            + '<button id="ma-save" class="kt-btn kt-btn-primary" type="button">Save</button>'
            + '</div></div>';
        document.body.appendChild(m);

        var msg = m.querySelector('#ma-msg');
        m.querySelector('#ma-cancel').onclick = function () { m.remove(); };

        m.querySelector('#ma-save').onclick = function () {
            var name = (m.querySelector('#ma-name').value || '').trim();
            var file = m.querySelector('#ma-file').files[0];
            var htmlEl = m.querySelector('#ma-html');
            var htmlTxt = htmlEl ? (htmlEl.value || '').trim() : '';
            if (!name) { msg.style.color = '#B91C1C'; msg.textContent = 'Give it a name so you can find it again.'; return; }
            if (!file && !htmlTxt) {
                msg.style.color = '#B91C1C';
                msg.textContent = kind === 'hero' ? 'Choose an image.' : 'Add an image or some text.';
                return;
            }

            var fd = new FormData();
            fd.append('kind', kind);
            fd.append('name', name);
            if (htmlTxt) { fd.append('html', htmlTxt); }
            if (file) { fd.append('image', file, file.name); }

            var btn = m.querySelector('#ma-save');
            btn.disabled = true;
            msg.style.color = '#64748B';
            msg.textContent = 'Saving…';

            Api.post('/marketing/assets', fd).then(function (res) {
                /* Reload first, THEN hand the new asset back: the picker selects by id
                   from the library, so telling it about an asset the library has not
                   heard of yet would select nothing. */
                return loadAssets().then(function () {
                    if (onSaved) { onSaved(res || null); }
                    m.remove();
                });
            }).catch(function (e) {
                btn.disabled = false;
                msg.style.color = '#B91C1C';
                msg.textContent = (e && e.message) || 'Could not save that.';
            });
        };
    }

    function loadAssets() {
        return Api.get('/marketing/assets').then(function (r) {
            assetState.list = (r && r.data) || [];
        }).catch(function () { assetState.list = []; });
    }

    var headerBlock = assetPicker('header', 'Header (optional)',
        'Sits above the message. Upload once and reuse it on every campaign.',
        { selectedId: existing ? existing.header_asset_id : null });
    var footerBlock = assetPicker('footer', 'Footer (optional)',
        'Sits below the message — an address, a sign-off, unsubscribe wording.',
        { selectedId: existing ? existing.footer_asset_id : null });
    body.appendChild(headerBlock);
    body.appendChild(footerBlock);

    /* All three restore together, after the library has arrived — none of them can show a
       selection before there is a list to select from. */
    loadAssets().then(function () {
        heroPicker._restore();
        headerBlock._restore();
        footerBlock._restore();
    });

    body.appendChild(buildRichEditor(existing ? existing.body_html : '', function () { return null; }));

    // Schedule
    body.appendChild(labelEl('Schedule (optional — leave blank to send manually)'));
    var schedIn = Dom.el('input', { type: 'datetime-local', value: existing && existing.scheduled_for ? toDatetimeLocal(existing.scheduled_for) : '', style: inputStyle() });
    body.appendChild(schedIn);

    // Action row
    var actions = Dom.el('div', { style: 'display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:18px;padding-top:18px;border-top:1px solid #E5E7EB;flex-wrap:wrap;' });
    var leftBtns = Dom.el('div', { style: 'display:flex;gap:8px;' });
    if (existing) {
      var delBtn = Dom.el('button', { style: btnDanger() }, 'Delete');
      delBtn.addEventListener('click', async function () {
        if (!await KT.confirm('Delete this campaign? This cannot be undone.')) return;
        Api.delete('/marketing/campaigns/' + existing.id).then(function () { overlay.remove(); render(container); });
      });
      leftBtns.appendChild(delBtn);
    }
    actions.appendChild(leftBtns);

    var rightBtns = Dom.el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;' });
    var previewBtn = Dom.el('button', { style: btnSecondary() }, '👁 Preview');
    var saveBtn = Dom.el('button', { style: btnSecondary() }, 'Save draft');
    var sendBtn = Dom.el('button', { style: btnPrimary() }, 'Send now');
    rightBtns.appendChild(previewBtn);
    rightBtns.appendChild(saveBtn);
    rightBtns.appendChild(sendBtn);

    previewBtn.addEventListener('click', function () {
      var payload = collect();
      if (!payload.body_html) { alert('Add some body content to preview.'); return; }
      previewBtn.disabled = true; previewBtn.textContent = 'Loading…';
      loadFeatures().then(function (f) {
        /* assetState belongs to THIS function. openPreview is a sibling, so it could
           never see it — the reference there threw ReferenceError on every preview
           (ticket #58). Passed in, which also makes it obvious that the preview needs
           the chosen header and footer to be worth looking at. */
        openPreview(payload, f.branding, f.whiteLabel, assetState);
      }).finally(function () { previewBtn.disabled = false; previewBtn.innerHTML = '👁 Preview'; });
    });
    actions.appendChild(rightBtns);
    body.appendChild(actions);

    function collect() {
      var editor = modal.querySelector('.kt-rt-editor');
      return {
        title: titleIn.value.trim(),
        subject: subjectIn.value.trim() || null,
        body_html: editor ? editor.innerHTML : '',
        header_asset_id: assetState.header,
        footer_asset_id: assetState.footer,
        hero_image_url: heroUrl || null,
        audience: audSel.value,
        channel: chSel.value,
        scheduled_for: schedIn.value ? schedIn.value.replace('T', ' ') + ':00' : null,
      };
    }

    saveBtn.addEventListener('click', function () {
      var payload = collect();
      if (!payload.title) { alert('Title is required.'); return; }
      if (!payload.body_html) { alert('Body is required.'); return; }
      payload.status = payload.scheduled_for ? 'scheduled' : 'draft';
      var promise = existing
        ? Api.patch('/marketing/campaigns/' + existing.id, payload)
        : Api.post('/marketing/campaigns', payload);
      saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
      promise.then(function () { overlay.remove(); render(container); })
        .catch(function (e) { alert('Save failed: ' + e.message); saveBtn.disabled = false; saveBtn.textContent = 'Save draft'; });
    });

    sendBtn.addEventListener('click', async function () {
      if (!await KT.confirm('Send this campaign now? This cannot be undone.')) return;
      var payload = collect();
      if (!payload.title || !payload.body_html) { alert('Title and body are required before sending.'); return; }
      sendBtn.disabled = true; sendBtn.textContent = 'Sending…';
      var ensure = existing
        ? Api.patch('/marketing/campaigns/' + existing.id, payload).then(function () { return existing.id; })
        : Api.post('/marketing/campaigns', payload).then(function (r) { return r.id; });
      ensure
        .then(function (id) { return Api.post('/marketing/campaigns/' + id + '/send', {}); })
        .then(function (r) { alert('Sent to ' + r.recipients + ' recipients via ' + r.channel + '.'); overlay.remove(); render(container); })
        .catch(function (e) { alert('Send failed: ' + e.message); sendBtn.disabled = false; sendBtn.textContent = 'Send now'; });
    });

    document.body.appendChild(overlay);
  }

  function toDatetimeLocal(d) {
    try { var dt = new Date(d); return dt.toISOString().slice(0, 16); } catch (e) { return ''; }
  }

  // ── Mini rich-text editor — contentEditable + toolbar ──────────────
  function buildRichEditor(initialHtml, getContext) {
    var wrap = Dom.el('div', { style: 'border:1px solid #D1D5DB;border-radius:10px;overflow:hidden;margin-bottom:14px;background:white;' });
    var toolbar = Dom.el('div', { style: 'display:flex;flex-wrap:wrap;gap:4px;padding:8px;border-bottom:1px solid #E5E7EB;background:#FAFBFC;' });

    function btn(label, title, action, extraStyle) {
      var b = Dom.el('button', { type: 'button', title: title, style: 'background:white;border:1px solid #E5E7EB;border-radius:6px;padding:5px 9px;font-size:13px;cursor:pointer;color:#374151;min-width:30px;transition:background .12s,border-color .12s;' + (extraStyle || '') }, label);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep focus on editor
      b.addEventListener('click', action);
      b.addEventListener('mouseenter', function () { b.style.background = '#F3F4F6'; b.style.borderColor = '#D1D5DB'; });
      b.addEventListener('mouseleave', function () { b.style.background = 'white'; b.style.borderColor = '#E5E7EB'; });
      return b;
    }
    function selectStyle() { return 'background:white;border:1px solid #E5E7EB;border-radius:6px;padding:4px 6px;font-size:12px;color:#374151;cursor:pointer;'; }
    // v22p35: enable styleWithCSS so foreColor/hiliteColor produce inline-style
    // spans the sanitiser allows, rather than deprecated <font> tags. Also try
    // both hiliteColor (Chrome / Webkit) and backColor (Firefox) so highlight
    // works across browsers.
    function exec(cmd, val) {
      try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
      if (cmd === 'hiliteColor') {
        if (!document.execCommand('hiliteColor', false, val || null)) {
          document.execCommand('backColor', false, val || null);
        }
      } else {
        document.execCommand(cmd, false, val == null ? null : val);
      }
      editor.focus();
    }

    // ── Font family ───────────────────────────────────────────────
    var fontSel = Dom.el('select', { title: 'Font family', style: selectStyle() });
    var fonts = [
      ['', 'Default'],
      ['Inter, system-ui, sans-serif', 'Inter (clean)'],
      ['Georgia, "Times New Roman", serif', 'Georgia (serif)'],
      ['"Helvetica Neue", Arial, sans-serif', 'Helvetica'],
      ['"Courier New", monospace', 'Courier (mono)'],
      ['"Comic Sans MS", "Comic Sans", cursive', 'Comic Sans'],
      ['Verdana, sans-serif', 'Verdana'],
      ['"Trebuchet MS", sans-serif', 'Trebuchet'],
    ];
    fonts.forEach(function (f) { var o = Dom.el('option', { value: f[0] }, f[1]); fontSel.appendChild(o); });
    fontSel.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    fontSel.addEventListener('change', function () { if (fontSel.value) { exec('fontName', fontSel.value); } fontSel.selectedIndex = 0; });
    toolbar.appendChild(fontSel);

    // ── Font size (1=8px ... 7=36px in execCommand) ───────────────
    var sizeSel = Dom.el('select', { title: 'Text size', style: selectStyle() });
    [['', 'Size'], ['1', 'Tiny'], ['2', 'Small'], ['3', 'Normal'], ['4', 'Medium'], ['5', 'Large'], ['6', 'X-large'], ['7', 'Huge']].forEach(function (s) {
      sizeSel.appendChild(Dom.el('option', { value: s[0] }, s[1]));
    });
    sizeSel.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    sizeSel.addEventListener('change', function () { if (sizeSel.value) exec('fontSize', sizeSel.value); sizeSel.selectedIndex = 0; });
    toolbar.appendChild(sizeSel);
    toolbar.appendChild(divider());

    // ── Bold / italic / underline / strike ────────────────────────
    toolbar.appendChild(btn('B', 'Bold (Ctrl/Cmd+B)', function () { exec('bold'); }, 'font-weight:800;'));
    toolbar.appendChild(btn('I', 'Italic (Ctrl/Cmd+I)', function () { exec('italic'); }, 'font-style:italic;'));
    toolbar.appendChild(btn('U', 'Underline (Ctrl/Cmd+U)', function () { exec('underline'); }, 'text-decoration:underline;'));
    toolbar.appendChild(btn('S', 'Strikethrough', function () { exec('strikeThrough'); }, 'text-decoration:line-through;'));
    toolbar.appendChild(divider());

    // ── Text colour + highlight (background) ──────────────────────
    var fgInput = Dom.el('input', { type: 'color', title: 'Text colour', value: '#1F6080', style: 'width:34px;height:30px;padding:0;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;' });
    fgInput.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    fgInput.addEventListener('input', function () { exec('foreColor', fgInput.value); });
    toolbar.appendChild(fgInput);
    var bgInput = Dom.el('input', { type: 'color', title: 'Highlight colour', value: '#FEF3C7', style: 'width:34px;height:30px;padding:0;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;' });
    bgInput.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    bgInput.addEventListener('input', function () { exec('hiliteColor', bgInput.value); });
    toolbar.appendChild(bgInput);
    toolbar.appendChild(divider());

    // ── Headings + paragraph ──────────────────────────────────────
    toolbar.appendChild(btn('H1', 'Heading 1', function () { exec('formatBlock', '<h1>'); }));
    toolbar.appendChild(btn('H2', 'Heading 2', function () { exec('formatBlock', '<h2>'); }));
    toolbar.appendChild(btn('H3', 'Heading 3', function () { exec('formatBlock', '<h3>'); }));
    toolbar.appendChild(btn('P', 'Paragraph', function () { exec('formatBlock', '<p>'); }));
    toolbar.appendChild(divider());

    // ── Alignment ─────────────────────────────────────────────────
    toolbar.appendChild(btn('⬱', 'Align left',    function () { exec('justifyLeft'); }));
    toolbar.appendChild(btn('☰', 'Align centre',  function () { exec('justifyCenter'); }));
    toolbar.appendChild(btn('⬲', 'Align right',   function () { exec('justifyRight'); }));
    toolbar.appendChild(btn('☷', 'Justify',       function () { exec('justifyFull'); }));
    toolbar.appendChild(divider());

    // ── Lists + quote ─────────────────────────────────────────────
    toolbar.appendChild(btn('•', 'Bulleted list', function () { exec('insertUnorderedList'); }));
    toolbar.appendChild(btn('1.', 'Numbered list', function () { exec('insertOrderedList'); }));
    toolbar.appendChild(btn('❝', 'Block quote', function () { exec('formatBlock', '<blockquote>'); }));
    toolbar.appendChild(btn('⇥', 'Indent', function () { exec('indent'); }));
    toolbar.appendChild(btn('⇤', 'Outdent', function () { exec('outdent'); }));
    toolbar.appendChild(divider());

    // ── Line break + horizontal rule ──────────────────────────────
    toolbar.appendChild(btn('↵', 'Soft line break (no paragraph)', function () {
      editor.focus();
      document.execCommand('insertHTML', false, '<br />');
    }));
    toolbar.appendChild(btn('─', 'Horizontal rule', function () {
      editor.focus();
      document.execCommand('insertHTML', false, '<hr style="border:none;border-top:1px solid #E5E7EB;margin:14px 0;" />');
    }));
    toolbar.appendChild(divider());

    // ── Link + image ──────────────────────────────────────────────
    toolbar.appendChild(btn('🔗', 'Insert link', function () {
      var url = prompt('Link URL (https://…):');
      if (url) exec('createLink', url);
    }));
    toolbar.appendChild(btn('🚫🔗', 'Remove link', function () { exec('unlink'); }));
    var imgBtn = btn('🖼 Image', 'Insert image', function () { fileIn.click(); });
    toolbar.appendChild(imgBtn);
    var fileIn = Dom.el('input', { type: 'file', accept: 'image/*', style: 'display:none;' });
    fileIn.addEventListener('change', function () {
      var f = fileIn.files[0]; if (!f) return;
      var fd = new FormData(); fd.append('image', f);
      imgBtn.disabled = true; imgBtn.textContent = '⏳';
      Api.postForm('/marketing/images', fd).then(function (r) {
        editor.focus();
        var html = '<img src="' + absUrl(r.url) + '" alt="" style="max-width:100%;height:auto;border-radius:8px;margin:8px 0;" />';
        document.execCommand('insertHTML', false, html);
      }).catch(function (e) { alert('Image upload failed: ' + e.message); })
        .finally(function () { imgBtn.disabled = false; imgBtn.innerHTML = '🖼 Image'; });
    });
    toolbar.appendChild(fileIn);
    toolbar.appendChild(divider());

    // ── Undo / redo / clear ───────────────────────────────────────
    toolbar.appendChild(btn('↶', 'Undo', function () { exec('undo'); }));
    toolbar.appendChild(btn('↷', 'Redo', function () { exec('redo'); }));
    toolbar.appendChild(btn('clear', 'Remove formatting', function () { exec('removeFormat'); }));

    wrap.appendChild(toolbar);
    var editor = Dom.el('div', { class: 'kt-rt-editor', contenteditable: 'true', style: 'min-height:260px;padding:14px 16px;font-size:14px;line-height:1.5;color:#111827;outline:none;' });
    editor.innerHTML = initialHtml || '<p>Type your message here…</p>';
    wrap.appendChild(editor);
    return wrap;
  }

  function divider() { return Dom.el('div', { style: 'width:1px;background:#E5E7EB;margin:0 4px;' }); }

  // ── Branded preview ────────────────────────────────────────────────
  // Renders the campaign as recipients will see it: optional white-label
  // header (logo + agency details), the message body, and an always-present
  // "Powered by Kiddietrac" footer carrying the agency's privacy & terms links.
  /**
   * The campaign as the reader will get it.
   *
   * `assets` is the composer's header/footer selection: { header, footer, list }. It used
   * to be reached for as a bare `assetState`, which is declared inside openComposer — a
   * sibling function — so every click on Preview threw ReferenceError and the overlay
   * never opened (ticket #58, 2026-09-09). Passed in now, and defaulted, so a caller that
   * has no assets gets a preview without header and footer rather than an exception.
   */
  function openPreview(payload, brand, whiteLabel, assets) {
    brand = brand || {};
    assets = assets || { header: null, footer: null, list: [] };
    if (!Array.isArray(assets.list)) { assets.list = []; }
    var color = brand.brand_primary_color || '#1F6080';
    var name = brand.brand_name || 'Your agency';
    var logo = brand.brand_logo_url ? absUrl(brand.brand_logo_url) : '';
    var hero = payload.hero_image_url ? absUrl(payload.hero_image_url) : '';

    var headerHtml;
    if (whiteLabel) {
      headerHtml =
        '<div style="border-top:5px solid ' + esc(color) + ';background:#fff;padding:20px 28px;border-bottom:1px solid #EEF0F2;display:flex;align-items:center;gap:14px;">' +
          (logo
            ? '<img src="' + esc(logo) + '" alt="" style="max-height:54px;max-width:200px;object-fit:contain;flex-shrink:0;" />'
            : '<div style="font-size:20px;font-weight:800;color:' + esc(color) + ';flex-shrink:0;">' + esc(name) + '</div>') +
          '<div style="flex:1;text-align:right;font-size:12px;color:#6B7280;line-height:1.5;">' +
            (logo ? '<div style="font-weight:700;color:#111827;font-size:14px;">' + esc(name) + '</div>' : '') +
            (brand.brand_support_email ? '<div>' + esc(brand.brand_support_email) + '</div>' : '') +
            (brand.brand_address ? '<div>' + esc(brand.brand_address).replace(/\n/g, '<br>') + '</div>' : '') +
          '</div>' +
        '</div>';
    } else {
      headerHtml = '<div style="height:5px;background:' + esc(color) + ';"></div>';
    }

    var privacy = normUrl(brand.brand_privacy_url);
    var terms = normUrl(brand.brand_terms_url);
    var legal = [];
    if (privacy) legal.push('<a href="' + esc(privacy) + '" target="_blank" rel="noopener" style="color:#6B7280;text-decoration:underline;">Privacy policy</a>');
    if (terms) legal.push('<a href="' + esc(terms) + '" target="_blank" rel="noopener" style="color:#6B7280;text-decoration:underline;">Terms &amp; conditions</a>');
    var legalRow = legal.length
      ? '<div style="margin-top:6px;">' + legal.join(' &nbsp;·&nbsp; ') + '</div>'
      : '<div style="margin-top:6px;color:#B0B6BE;">Add privacy &amp; terms links under Settings → Branding</div>';

    var footerHtml =
      '<div style="background:#F9FAFB;border-top:1px solid #EEF0F2;padding:18px 28px;text-align:center;font-size:11px;color:#64748B;">' +
        '<div style="font-weight:700;color:#6B7280;">Powered by Kiddietrac</div>' +
        legalRow +
        '<div style="margin-top:8px;">You’re receiving this because you are part of ' + esc(name) + '.</div>' +
      '</div>';

    var inner =
      '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.12);font-family:Inter,system-ui,sans-serif;">' +
        headerHtml +
        (payload.subject ? '<div style="padding:18px 28px 0;font-size:18px;font-weight:800;color:#111827;">' + esc(payload.subject) + '</div>' : '') +
        (hero ? '<div style="padding:16px 28px 0;"><img src="' + esc(hero) + '" alt="" style="width:100%;border-radius:8px;display:block;" /></div>' : '') +
        /* The preview has to include the header and footer, or it is a preview of
           something nobody receives. */
        (function () {
          var a = assets.list.filter(function (x) { return String(x.id) === String(assets.header); })[0];
          if (!a) { return ''; }
          return (a.image_url ? '<img src="' + esc(a.image_url) + '" alt="" style="display:block;width:100%;">' : '')
            + (a.html || '');
        })() +
        '<div style="padding:18px 28px 24px;font-size:14px;line-height:1.6;color:#1F2937;">' + (payload.body_html || '') + '</div>' +
        (function () {
          var a = assets.list.filter(function (x) { return String(x.id) === String(assets.footer); })[0];
          if (!a) { return ''; }
          return (a.image_url ? '<img src="' + esc(a.image_url) + '" alt="" style="display:block;width:100%;">' : '')
            + (a.html ? '<div style="padding:0 28px 20px;font-size:13px;color:#64748B;">' + a.html + '</div>' : '');
        })() +
        footerHtml +
      '</div>';

    var overlay = Dom.el('div', { style: 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1100;display:flex;flex-direction:column;align-items:center;padding:24px;overflow:auto;' });
    var topBar = Dom.el('div', { style: 'width:100%;max-width:600px;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;' });
    topBar.appendChild(Dom.el('div', { style: 'color:#fff;font-weight:700;font-size:14px;' }, '👁 Campaign preview' + (whiteLabel ? ' · white-label' : '')));
    var cls = Dom.el('button', { style: 'background:rgba(255,255,255,.16);color:#fff;border:none;border-radius:8px;padding:7px 14px;font-weight:700;cursor:pointer;' }, 'Close preview');
    cls.addEventListener('click', function () { overlay.remove(); });
    topBar.appendChild(cls);
    overlay.appendChild(topBar);
    var frame = Dom.el('div', { style: 'width:100%;' });
    frame.innerHTML = inner;
    overlay.appendChild(frame);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
  }

  // ── Tiny styling helpers ───────────────────────────────────────────
  function labelEl(t) { return Dom.el('label', { style: 'display:block;font-size:13px;font-weight:700;color:#374151;margin-bottom:6px;' }, t); }
  function inputStyle() { return 'width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;box-sizing:border-box;margin-bottom:14px;font-family:inherit;'; }
  function btnPrimary() { return 'background:#1F6080;color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }
  function btnSecondary() { return 'background:white;color:#1F6080;border:1px solid #1F6080;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }
  function btnDanger() { return 'background:white;color:#DC2626;border:1px solid #DC2626;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;'; }

  // ── Shell registration ─────────────────────────────────────────────
  if (Shell && Shell.registerScreen) {
    Shell.registerScreen('agency_admin:marketing-campaigns', render);
    Shell.registerScreen('centre_director:marketing-campaigns', render);
    Shell.registerScreen('platform_admin:marketing-campaigns', render);
  }
  // Also expose as window.KT for the legacy shim
  KT.Marketing = { render: render };
})(window);
