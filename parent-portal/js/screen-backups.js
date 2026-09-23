/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Settings → Backups  (platform admin only)

   The nightly dump has existed and worked for a while; what did not exist was any way to
   SEE it. "Do we have backups?" could only be answered by someone with SSH, which means
   in practice it was answered by assumption — and an assumed backup is the one that turns
   out to be three weeks stale on the day it matters.

   So this screen leads with the two facts that decide whether you are actually covered:
   how long ago the last good copy was taken, and whether the cron that takes it is still
   alive. A job configured perfectly and never run looks exactly like a healthy one from
   any screen that only shows settings.

   NOTHING HERE DOWNLOADS A DUMP, and there is no route that could. That file is every
   child, guardian, address and medical note on the platform in one 7 MB archive. Restores
   happen over SSH, by a person, on purpose.
   ═══════════════════════════════════════════════════════════════════ */
(function (window) {
  'use strict';
  var KT = window.KT;
  if (!KT || !KT.Shell || !KT.Shell.registerScreen) { return; }
  var Api = KT.Api;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function ago(hours) {
    if (hours == null) { return 'never'; }
    if (hours < 1) { return Math.max(1, Math.round(hours * 60)) + ' minutes ago'; }
    if (hours < 48) { return Math.round(hours) + ' hours ago'; }

    return Math.round(hours / 24) + ' days ago';
  }

  /* Green only when a good copy is genuinely recent. A backup that is two days old on a
     daily schedule means last night did not happen, and that should look wrong. */
  function health(st) {
    if (st.last_error) { return { c: '#B91C1C', t: 'Last run failed' }; }
    if (st.hours_since == null) { return { c: '#B45309', t: 'No backup recorded yet' }; }
    if (st.hours_since > 48) { return { c: '#B91C1C', t: 'Stale — more than 2 days old' }; }
    if (st.hours_since > 26) { return { c: '#B45309', t: 'Older than a day' }; }

    return { c: '#1E8E60', t: 'Healthy' };
  }

  async function render(main) {
    main.setAttribute('data-kt-pretty', '1');
    main.innerHTML = '<div style="padding:14px 24px;max-width:860px;">'
      + '<div class="kt-page-hero"><h2>🗄️ Database backups</h2>'
      + '<p>A compressed copy of the whole platform database, taken automatically every '
      + 'night and kept outside the web root.</p></div>'
      + '<div id="bk-body">Loading…</div></div>';

    await load(main);
  }

  async function load(main) {
    var body = main.querySelector('#bk-body');
    var res;
    try {
      res = await Api.get('/admin/backups');
    } catch (e) {
      body.innerHTML = '<div class="kt-card" style="max-width:660px;color:#B91C1C;">'
        + esc((e && e.message) || 'Could not load')
        + '<div style="color:#64748B;font-size:12.5px;margin-top:6px;">'
        + 'Backups are managed by platform administrators only.</div></div>';
      return;
    }

    var s = res.settings || {};
    var st = res.status || {};
    var h = health(st);

    body.innerHTML =
      // ── Status ────────────────────────────────────────────────────
      '<div class="kt-card" style="max-width:660px;">'
      + '<div style="display:flex;align-items:center;gap:10px;">'
      +   '<span style="font-size:22px;">●</span>'.replace('●', '<span style="color:' + h.c + ';">●</span>')
      +   '<div><div style="font-size:16px;font-weight:700;color:' + h.c + ';">' + esc(h.t) + '</div>'
      +     '<div style="font-size:13px;color:#475569;">Last good copy: <strong>' + esc(ago(st.hours_since)) + '</strong>'
      +     (st.last_ok_at ? ' <span style="color:#94A3B8;">(' + esc(st.last_ok_at) + ')</span>' : '')
      +     '</div></div></div>'
      + (st.last_error
          ? '<div style="margin-top:10px;background:#FEF2F2;border:1px solid #FECACA;border-radius:9px;'
            + 'padding:9px 11px;font-size:12.5px;color:#991B1B;">' + esc(st.last_error) + '</div>'
          : '')
      + '<div style="display:flex;gap:22px;flex-wrap:wrap;margin-top:12px;font-size:13px;color:#334155;">'
      +   '<div><b>' + (st.on_disk || 0) + '</b> copies kept</div>'
      +   '<div><b>' + (st.total_mb || 0) + ' MB</b> on disk</div>'
      +   (st.last_size_mb != null ? '<div>latest <b>' + st.last_size_mb + ' MB</b></div>' : '')
      + '</div>'
      /* The cron is the single point of failure for EVERY scheduled job here, not just
         this one. If it stopped, a settings screen that only showed settings would keep
         looking perfectly healthy. */
      + '<div style="margin-top:10px;font-size:12px;color:#64748B;">'
      +   'Scheduler last seen: ' + (st.scheduler_seen_at ? esc(st.scheduler_seen_at) : '<span style="color:#B91C1C;">never — the cron may not be running</span>')
      +   (st.writable ? '' : ' · <span style="color:#B91C1C;">backup folder is not writable</span>')
      + '</div></div>'

      // ── Settings ──────────────────────────────────────────────────
      + '<div class="kt-card" style="max-width:660px;margin-top:14px;">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;'
      +   'padding:10px 0;border-bottom:1px solid #F1F5F9;">'
      +   '<div><div style="font-size:14px;font-weight:600;color:#334155;">Nightly backup</div>'
      +     '<div style="font-size:12.5px;color:#64748B;">Off means no copy of this database is taken at all.</div></div>'
      +   '<input data-bk-enabled type="checkbox"' + (s.enabled ? ' checked' : '') + '>'
      + '</div>'
      + '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;'
      +   'padding:10px 0;border-bottom:1px solid #F1F5F9;">'
      +   '<div><div style="font-size:14px;font-weight:600;color:#334155;">Time</div>'
      +     '<div style="font-size:12.5px;color:#64748B;">Server time. Pick a quiet hour — the dump locks nothing, but it does read every table.</div></div>'
      +   '<input data-bk-time type="time" value="' + esc(s.time || '03:30') + '" '
      +     'style="padding:6px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;">'
      + '</div>'
      + '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;">'
      +   '<div><div style="font-size:14px;font-weight:600;color:#334155;">Keep</div>'
      +     '<div style="font-size:12.5px;color:#64748B;">Daily copies to retain. Older ones are deleted after each run.</div></div>'
      +   '<div><input data-bk-keep type="number" min="1" max="90" value="' + (s.keep || 14) + '" '
      +     'style="width:80px;padding:6px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;"> '
      +     '<span style="font-size:12.5px;color:#64748B;">days</span></div>'
      + '</div>'
      + '<div style="display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;">'
      +   '<button data-bk-save class="kt-btn kt-btn-primary">Save</button>'
      +   '<button data-bk-run class="kt-btn" style="border:1px solid #CBD5E1;background:#fff;">Back up now</button>'
      +   '<span data-bk-msg style="font-size:13px;"></span>'
      + '</div></div>'

      // ── What is on disk ───────────────────────────────────────────
      + '<div class="kt-card" style="max-width:660px;margin-top:14px;">'
      + '<div style="font-size:14px;font-weight:700;color:#334155;margin-bottom:8px;">Copies on disk</div>'
      + (res.backups && res.backups.length
          ? '<table style="width:100%;font-size:13px;"><tbody>'
            + res.backups.map(function (b) {
                return '<tr><td style="padding:4px 0;color:#334155;">' + esc(b.taken_at) + '</td>'
                  + '<td style="padding:4px 0;color:#64748B;">' + esc(b.name) + '</td>'
                  + '<td style="padding:4px 0;text-align:right;color:#334155;">' + b.size_mb + ' MB</td></tr>';
              }).join('')
            + '</tbody></table>'
          : '<div style="color:#64748B;font-size:13px;">Nothing yet.</div>')
      + '<div style="margin-top:12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:9px;'
      +   'padding:10px 12px;font-size:12px;color:#475569;line-height:1.55;">'
      +   '<b>These cannot be downloaded from the portal, on purpose.</b> One file is every '
      +   'child, guardian, address and medical note on the platform. They are stored outside '
      +   'the web root and restored over SSH:<br>'
      +   '<code style="font-size:11.5px;color:#334155;">gunzip &lt; backups/kiddietrac-….sql.gz | mysql -u&lt;user&gt; -p &lt;db&gt;</code>'
      + '</div></div>';

    wire(main, body);
  }

  function wire(main, box) {
    var msg = box.querySelector('[data-bk-msg]');

    box.querySelector('[data-bk-save]').addEventListener('click', function () {
      var btn = box.querySelector('[data-bk-save]');
      var enabled = box.querySelector('[data-bk-enabled]').checked;
      btn.disabled = true;
      msg.style.color = '#64748B';
      msg.textContent = 'Saving…';

      Api.post('/admin/backups/settings', {
        enabled: enabled,
        time: box.querySelector('[data-bk-time]').value || '03:30',
        keep: parseInt(box.querySelector('[data-bk-keep]').value, 10) || 14
      }).then(function () {
        btn.disabled = false;
        msg.style.color = enabled ? '#1E8E60' : '#B45309';
        /* Turning it OFF is said plainly rather than with a tick. Somebody should not be
           able to disable the only copy of this database and get a green "Saved". */
        msg.textContent = enabled ? '✓ Saved' : '⚠ Saved — nightly backups are now OFF';
        setTimeout(function () { load(main); }, 900);
      }).catch(function (e) {
        btn.disabled = false;
        msg.style.color = '#B91C1C';
        msg.textContent = (e && e.message) || 'Could not save';
      });
    });

    box.querySelector('[data-bk-run]').addEventListener('click', function () {
      var btn = box.querySelector('[data-bk-run]');
      btn.disabled = true;
      msg.style.color = '#64748B';
      msg.textContent = 'Taking a backup… this reads every table, give it a moment.';

      Api.post('/admin/backups/run', {}).then(function (r) {
        btn.disabled = false;
        msg.style.color = '#1E8E60';
        msg.textContent = '✓ Backup taken';
        load(main);
      }).catch(function (e) {
        btn.disabled = false;
        msg.style.color = '#B91C1C';
        msg.textContent = (e && e.message) || 'Backup failed — see the status above';
        load(main);
      });
    });
  }

  KT.Backups = { render: render };
  /* Platform admin only. One database sits behind every agency, so this is not an
     agency's own data to manage — the controller re-checks. */
  KT.Shell.registerScreen('platform_admin:backups', render);
})(window);
