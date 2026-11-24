/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC — Parent Portal Shared JS
   API client, auth helpers, formatting, error handling
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const API_BASE = (window.KT_CONFIG && window.KT_CONFIG.apiBase)
    || 'https://api.kiddietrac.com/api/v1';

  /* THE SAME READ, ONCE (2026-11-13, portal speed sweep). Modules that read with plain fetch() - the top bar, the MFA
     nudge, the PIN / fingerprint unlock, the password gate - asked for /auth/me, /auth/mfa/status and
     /admin/activity-feed at the same moment as the shell, so a refresh sent them twice to a two-core server already
     busy with everything else. A GET to the API identical to one still on its way (same address, sign-in, agency and
     view-as) now shares that request; each caller gets its own copy of the response. Never a request carrying a
     cancel signal or a cache choice, never a write, and nothing is kept once the answer has arrived. */
  (function shareReads() {
    const real = window.fetch;
    if (typeof real !== 'function' || real.__ktShared) return;
    const flight = new Map();
    const hdr = function (h, name) {
      if (!h) return '';
      try {
        if (typeof Headers !== 'undefined' && h instanceof Headers) return h.get(name) || '';
        for (const k in h) { if (k.toLowerCase() === name.toLowerCase()) return String(h[k]); }
      } catch (e) {}
      return '';
    };
    const shared = function (input, init) {
      try {
        if (typeof input === 'string' && input.indexOf('/api/v1/') !== -1
            && (!init || ((!init.method || String(init.method).toUpperCase() === 'GET') && !init.signal && !init.cache && !init.body))) {
          const h = init && init.headers;
          const key = input + '|' + hdr(h, 'Authorization') + '|' + hdr(h, 'X-Active-Agency-Id') + '|' + hdr(h, 'X-View-As-Role');
          let p = flight.get(key);
          if (!p) {
            p = real.apply(this, arguments);
            flight.set(key, p);
            const done = function () { if (flight.get(key) === p) flight.delete(key); };
            p.then(done, done);
          }
          return p.then(function (r) { return r.clone(); });
        }
      } catch (e) {}
      return real.apply(this, arguments);
    };
    shared.__ktShared = true;
    window.fetch = shared;
  })();

  // ─── Auth ───────────────────────────────────────────────────────
  const Auth = {
    // Fall back to localStorage so the session survives a WebView process kill
    // (which wipes sessionStorage) — an educator using the app all day shouldn't be
    // bounced to login every time Android reclaims the tab. The token is only ever
    // MIRRORED to localStorage when no biometric/PIN lock is enrolled (see
    // rememberSession below); enrolled users keep their locked kt_bio_/kt_pin_ vault.
    token() { try { return sessionStorage.getItem('kt_token') || localStorage.getItem('kt_token'); } catch (e) { return null; } },
    user() {
      try { return JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || 'null'); }
      catch (_) { return null; }
    },
    // Keep the user signed in across app restarts. Mirrors the live session token
    // into localStorage (survives a WebView kill) and restores it back into
    // sessionStorage on a cold launch. Skipped when a biometric/PIN lock is enrolled
    // — those flows own persistence with their own encrypted/locked vault, and we
    // must not bypass a lock the user deliberately turned on. Sign out still purges
    // both stores (Auth.clear), so this never keeps a token past an explicit logout.
    rememberSession() {
      try {
        if (localStorage.getItem('kt_biometric_enabled') === '1' || localStorage.getItem('kt_pin_enabled') === '1') return;
        /* A test tab (support / QA sign-in kept in this tab only) sets kt_no_persist in its
           own sessionStorage. localStorage is shared by every tab of the browser, so mirroring
           a test session there signs the owner's real tabs in as the test account. */
        if (sessionStorage.getItem('kt_no_persist') === '1') return;
        var s = sessionStorage.getItem('kt_token');
        var l = localStorage.getItem('kt_token');
        if (s) {
          localStorage.setItem('kt_token', s);
          var su = sessionStorage.getItem('kt_user'); if (su) localStorage.setItem('kt_user', su);
        } else if (l) {
          sessionStorage.setItem('kt_token', l);
          var lu = localStorage.getItem('kt_user'); if (lu) sessionStorage.setItem('kt_user', lu);
        }
      } catch (e) {}
    },
    /* THE ROLE THIS ACCOUNT LAST CHOSE (2026-09-17).

       Anthony: "default my role to super admin or the last role that I chose when logging
       off." A fresh sign-in starts with an empty sessionStorage, so kt_view_as is unset
       and the account lands in its REAL role - a super admin as super admin, which is the
       first half of the ask. This is the second half: if they deliberately picked a role
       before, put them back in it.

       Keyed to the user id, so the next person to sign in on a shared tablet gets their
       own answer and not the last person's ([[kiddietrac-active-agency-localstorage]]).
       Anything that does not match is ignored, which lands on the real role. */
    applyViewAsPreference() {
      try {
        var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
        if (!u || !u.id) { return; }
        var raw = localStorage.getItem('kt_view_as_pref');
        var p = raw ? JSON.parse(raw) : null;
        if (p && String(p.uid) === String(u.id) && typeof p.role === 'string' && p.role) {
          sessionStorage.setItem('kt_view_as', p.role);
        } else {
          sessionStorage.removeItem('kt_view_as');
        }
      } catch (e) {}
    },
    clear() {
      // Every way a session ends passes here (or calls KT.markSignedOut itself).
      var shared = ownsSharedSession();   // before markSignedOut / the removals below
      markSignedOut();
      // SECURITY: the bearer token is also written to localStorage (biometric
      // sign-in, set-password) and every helper reads sessionStorage||localStorage,
      // so a session-only clear left the token live on a shared device after
      // sign-out / expiry. Purge both stores.
      try {
        sessionStorage.removeItem('kt_token'); sessionStorage.removeItem('kt_user');
        if (shared) { localStorage.removeItem('kt_token'); localStorage.removeItem('kt_user'); }
        // The password-change flag belongs to the session that raised it (2026-10-22).
        sessionStorage.removeItem('kt_force_password_change'); sessionStorage.removeItem('kt_force_password_reason');
        /* The view-as PREVIEW dies with the session; the remembered PREFERENCE does not,
           because remembering it across a sign-out is the whole point. It is safe to keep
           only because it carries the user id it belongs to and is ignored for anybody
           else. */
        sessionStorage.removeItem('kt_view_as');
      } catch (e) {}
    },
    requireLogin() {
      if (!this.token()) {
        window.location.href = '/index.html';
        return false;
      }
      return true;
    },
    async logout() {
      // If biometric/PIN unlock is enrolled, DON'T revoke the token server-side.
      // The biometric/PIN vault stores THIS token to re-open the app on the next
      // launch; revoking it here made the unlock's /auth/me return 401, which
      // wiped the enrolment and bounced the user to the password login ("session
      // ended"). Ordinary sign-out keeps the credential for biometric re-entry
      // (the user's chosen behaviour); fully removing it = Settings → turn off
      // biometric, which clears the vault and lets logout revoke normally.
      var keepToken = false;
      try {
        keepToken = (localStorage.getItem('kt_biometric_enabled') === '1' && !!localStorage.getItem('kt_bio_token'))
          || (localStorage.getItem('kt_pin_enabled') === '1' && !!localStorage.getItem('kt_pin_vault'));
      } catch (e) {}
      // The portal fades out while the session ends (window.KT_out, kt-signout-fade.js).
      var out = window.KT_out;
      window.__ktLoggingOut = true;
      if (out) out.cover();
      if (!keepToken) { try { await Api.post('/auth/logout'); } catch (_) {} }
      this.clear();
      if (out) out.go('/index.html'); else window.location.href = '/index.html';
    },
  };

  /* ONE SIGN-OUT MARK (2026-10-21). Every way a session ends - the menu, the profile
     menu, the home-screen button, a timeout, a 401, access changed, another tab - leaves
     the device the same way, so the sign-in page that follows is right from its first
     frame:
       - kt_signed_out: straight to the sign-in form (launch scene), no biometric
         auto-prompt (kt-biometric reads and clears it);
       - kt_bio_unlocked_at gone, so the dashboard does not re-open the session;
       - the SHARED brand cache (kt_branding) gone, unless it is this app build's own
         tenant (?tenant= / kt_tenant): a white-label session's logo must not dress the
         next person's sign-in. Each account's own kt_brand_a<id> stays - the unlock
         screen and the first frames after that account's next sign-in use it.
     Before this only the home-screen sign-out set the mark, so a menu sign-out left the
     white-label brand in the shared cache. */
  /* WHOSE SIGN-IN IS IN localStorage (2026-11-13). A tab-only session (kt_no_persist: a support / QA / demo sign-in)
     never mirrors itself into localStorage (rememberSession), so what is there belongs to the browser's owner - and
     every tab shares it. Ending the tab-only session (a 401, an idle timeout, Sign out) used to purge it anyway: the
     owner's other tabs were signed out (kt-signout-fade follows a removed kt_token) and the next launch came up signed
     out. Such a tab clears the shared copy only when it IS this tab's token. Read BEFORE this tab's token is removed. */
  function ownsSharedSession() {
    try {
      if (sessionStorage.getItem('kt_no_persist') !== '1') return true;
      var l = localStorage.getItem('kt_token');
      return !!l && l === sessionStorage.getItem('kt_token');
    } catch (e) { return true; }
  }
  function markSignedOut() {
    try {
      var L = localStorage;
      if (!ownsSharedSession()) {
        // Only this tab's session ends; the owner's sign-in, unlock and brand stay as they are.
        try { sessionStorage.removeItem('kt_active_agency_id'); sessionStorage.removeItem('kt_active_agency_name'); } catch (e) {}
        return;
      }
      L.setItem('kt_signed_out', '1');
      L.removeItem('kt_bio_unlocked_at');
      // The agency this tab was working in ends with the session.
      try { sessionStorage.removeItem('kt_active_agency_id'); sessionStorage.removeItem('kt_active_agency_name'); } catch (e) {}
      var c = null; try { c = JSON.parse(L.getItem('kt_branding') || 'null'); } catch (e) {}
      var slug = null; try { slug = sessionStorage.getItem('kt_tenant'); } catch (e) {}
      if (!(c && slug && (c.agency_slug === slug || c.reseller_slug === slug))) L.removeItem('kt_branding');
    } catch (e) {}
  }
  window.KT = window.KT || {};
  window.KT.markSignedOut = markSignedOut;
  window.KT.ownsSharedSession = ownsSharedSession;

  // ─── API client ─────────────────────────────────────────────────
  const Api = {
    /* HOW MANY REQUESTS ARE OUTSTANDING RIGHT NOW.
       The shell's background-refresh cover (app-v2-shell __ktWaitSettled) needs to know
       when a screen has finished loading, and a screen's render function returns long
       before that: almost every screen here paints a "Loading..." line, fires
       Api.get().then(...) and returns immediately. Counting here — the one place every
       request passes through — means no screen has to announce anything. */
    async request(path, opts) {
      try { window.__ktInflight = (window.__ktInflight || 0) + 1; } catch (e) {}
      /* ...AND HOW MANY OF THEM A SCREEN ASKED FOR (2026-11-02). The phone's boot cover waits
         for the first screen's data, but __ktInflight also counts the page's own background
         modules - markers, pulse, the notification/agreement/MFA gates, currency, jurisdiction -
         which start one after another for seconds after boot. Measured on the unlock path: the
         home screen was drawn at 0.56s and the cover stayed up until 3.3s waiting on that
         chatter (Anthony's Samsung: ~6s of splash after the fingerprint). Screens live in
         /js/screen-*.js, so a request whose call stack passes through one is the screen's. */
      let _ktScreenReq = false;
      try { _ktScreenReq = /\/js\/screen-[^\/]*\.js/.test(String(new Error().stack || '')); } catch (e) {}
      if (_ktScreenReq) { try { window.__ktScreenInflight = (window.__ktScreenInflight || 0) + 1; } catch (e) {} }
      try {
        try { return await this._request(path, opts); }
        catch (err) {
          /* NOT SIGNED IN (2026-11-04). A care log or daily event for a child who is not signed in is
             refused with not_signed_in until the educator says so. Asked HERE so every screen that logs -
             care, the quick tiles, room logs, the sleep room - shows the same bold yellow warning
             (KT.warnNotSignedIn) instead of each needing its own. "Log anyway" re-posts with
             confirm_absent=1; Cancel throws a quiet error the screen can show or ignore (ktCancelled). */
          const b = opts && opts.body;
          if (err && err.status === 422 && err.data && err.data.message === 'not_signed_in'
              && opts && opts.method === 'POST' && b && typeof b === 'object' && !(b instanceof FormData) && !b.confirm_absent) {
            const ask = (window.KT && KT.warnNotSignedIn) ? KT.warnNotSignedIn(err.data)
              : Promise.resolve(window.confirm(err.data.prompt || 'This child is not signed in. Log anyway?'));
            if (await ask) return await this.request(path, Object.assign({}, opts, { body: Object.assign({}, b, { confirm_absent: 1 }) }));
            const names = (err.data.absent || []).join(', ');
            const e = new Error('Not logged - ' + (names || 'this child') + ((err.data.absent || []).length > 1 ? ' are' : ' is') + ' not signed in.');
            e.ktCancelled = true; e.status = 422; e.data = err.data;
            throw e;
          }
          throw err;
        }
      }
      finally {
        try { window.__ktInflight = Math.max(0, (window.__ktInflight || 1) - 1); } catch (e) {}
        if (_ktScreenReq) { try { window.__ktScreenInflight = Math.max(0, (window.__ktScreenInflight || 1) - 1); } catch (e) {} }
      }
    },
    async _request(path, { method = 'GET', body = null, query = null, _retried = false } = {}) {
      const opts_retried = _retried;
      let url = API_BASE + path;
      if (query) {
        const qs = new URLSearchParams(query).toString();
        if (qs) url += (path.includes('?') ? '&' : '?') + qs;
      }

      // A FormData body must NOT be JSON-encoded, and must NOT carry an explicit
      // Content-Type — the browser has to set multipart/form-data plus the
      // boundary itself. Without this, JSON.stringify(new FormData()) produced the
      // string "{}", so file uploads reached the API with NO fields at all and came
      // back as "The title field is required" plus one error per other field.
      const isForm = (typeof FormData !== 'undefined') && (body instanceof FormData);
      const headers = {
        'Accept': 'application/json',
      };
      if (!isForm) headers['Content-Type'] = 'application/json';
      const token = Auth.token();
      if (token) headers['Authorization'] = 'Bearer ' + token;
      // Multi-agency scope: tell the API which agency the user is currently
      // viewing (set by the agency switcher; a platform_admin can switch freely).
      // WITHOUT this, agency-scoped endpoints fall back to the user's first
      // agency, so opening a different agency showed the wrong agency's data.
      try {
        const _activeAgencyId = sessionStorage.getItem('kt_active_agency_id');
        if (_activeAgencyId) headers['X-Active-Agency-Id'] = _activeAgencyId;
        // "View as" role preview (platform_admin) — only Help reads this, to show
        // the previewed role's articles instead of the admin's own.
        const _viewAs = sessionStorage.getItem('kt_view_as');
        if (_viewAs) headers['X-View-As-Role'] = _viewAs;
      } catch (e) { /* sessionStorage unavailable */ }

      let res;
      const _started = Date.now();
      try {
        res = await fetch(url, {
          method,
          headers,
          body: body ? (isForm ? body : JSON.stringify(body)) : null,
        });
      } catch (e) {
        /* "NETWORK ERROR — CHECK YOUR CONNECTION" WAS USUALLY A LIE (2026-09-18).

           Anthony: "sometimes i get the Could not load: Network error - check your
           connection when using the portal - why?"

           fetch() rejects when the response never reaches JavaScript at all, and the
           commonest cause here is not the person's connection. It is a 508 Resource Limit
           Is Reached from the hosting account — 43,967 of them this month, all the same
           288-byte page — which is emitted by the web server before PHP runs and
           therefore carries no Access-Control-Allow-Origin. The browser then refuses to
           hand it over and rejects, so a server that answered in full looks identical to
           a dead wifi connection.

           Two things follow. The message should only blame the connection when the
           browser says the connection IS down; and a transient capacity error deserves
           one quiet retry before anybody is told anything, because by the next second the
           slot is usually free. */
        const offline = (typeof navigator !== 'undefined' && navigator.onLine === false);

        /* ONLY WHAT IS SAFE TO SEND TWICE (2026-09-21).

           I added this retry on 18 Sep without a method check, which was wrong. A
           rejected fetch means the RESPONSE never arrived - it does NOT mean the server
           did nothing. A POST that succeeded and lost its reply would be sent again, and
           for /provider/check-in that is a second check-in, for an invoice a second
           invoice, for a message a second message.

           Eisha, 21 Sep, 8:51:52am: check-in succeeds. 8:51:58: a second attempt is
           refused "Already checked in at 8:51 AM", and the browser recorded that the
           reply to it never arrived at all. That one was six seconds apart so it was her
           tapping again rather than this code - but it is exactly the shape this retry
           would produce, and on a slow endpoint it was a matter of time.

           GET and HEAD only. Everything else is handed back to the caller, which is the
           screen that knows whether asking twice is safe. */
        var _idempotent = (method === 'GET' || method === 'HEAD');

        if (_idempotent && !offline && !opts_retried) {
          await new Promise(r => setTimeout(r, 900));
          try {
            return await this._request(path, { method, body, query, _retried: true });
          } catch (again) {
            Api._reportFailure(path, method, 0, Date.now() - _started, 'fetch_rejected');
            throw again;
          }
        }

        /* The RETRY does not file its own report: the attempt that started this owns it,
           and reports the whole elapsed time rather than the few milliseconds the second
           try took. Without this the dedup below kept the inner report (ms: 0) and threw
           away the useful one. */
        if (!opts_retried) {
          Api._reportFailure(path, method, 0, Date.now() - _started, offline ? 'offline' : 'fetch_rejected');
        }
        throw new ApiError(
          'network',
          offline
            ? 'You appear to be offline — check your connection.'
            : 'The server could not be reached just now. It is usually busy rather than broken — please try again in a moment.',
          0
        );
      }

      /* A 5xx that DID arrive is reported too, so the audit log holds both halves of the
         picture: what PHP knows it returned, and what the browser actually received. */
      if (res.status >= 500) {
        Api._reportFailure(path, method, res.status, Date.now() - _started, 'server');
      }

      /* A PASSWORD CHANGE DEMANDED MID-SESSION (2026-09-21).

         EnsurePasswordChanged answers 403 to everything except auth/* while
         must_change_password is set. That flag can be raised while somebody is already
         signed in - an admin reset, or a password reaching its 90-day limit - and nothing
         on this side recognised the reply. The screen simply failed, repeatedly, with no
         explanation.

         Handled BEFORE the generic !res.ok below, so every screen inherits it without
         needing to know. Safe from looping: auth/* is allowed through the gate, so the
         change-password call itself can never produce this. */
      if (res.status === 403) {
        let _pw = null;
        try { _pw = await res.clone().json(); } catch (_) {}
        if (_pw && _pw.password_change_required) {
          try { sessionStorage.setItem('kt_force_password_change', '1'); } catch (e) {}
          /* Only move them once. A screen firing six parallel requests would otherwise
             re-navigate on each reply and the reason would flash past unread. */
          if (!window.__ktPwRedirect) {
            window.__ktPwRedirect = true;
            /* The locked dialog (kt-pw-gate.js, 2026-10-22), not a trip to Settings: the
               portal is not usable until the password is changed, so nothing else shows. */
            if (window.__ktPwGateShow) { window.__ktPwGateShow(_pw.reason, _pw.message); }
            else setTimeout(function () {
              if (!/#settings/.test(window.location.hash)) { window.location.hash = 'settings?tab=security'; }
            }, 300);
          }
          throw new ApiError('password_change_required',
            _pw.message || 'Please choose a new password to continue.', 403, _pw);
        }
        /* TWO-FACTOR REQUIRED (2026-11-10, EnsureStrongSignIn): every call but set-up answers 403 until an
           authenticator app or passkey is added. Hand it to the gate once; the screen just stops quietly. */
        if (_pw && _pw.mfa_setup_required) {
          if (!window.__ktMfaRedirect) {
            window.__ktMfaRedirect = true;
            if (window.__ktMfaGateShow) { window.__ktMfaGateShow(); }
          }
          throw new ApiError('mfa_setup_required',
            _pw.message || 'Set up two-factor sign-in to continue.', 403, _pw);
        }
      }

      /* SIGN-IN PAUSED (2026-11-10, App\Support\SignInLocks / EnforceSignInLocks): an admin locked this person, their
         centre or their agency. Every call but sign-out answers 423. Once per page: someone who still has another
         agency is moved to it (switch_to); anyone else is signed out on the server too and shown who to contact. */
      if (res.status === 423) {
        let _lk = null;
        try { _lk = await res.clone().json(); } catch (_) {}
        if (_lk && _lk.access_locked) {
          if (!window.__ktLockedOut) {
            window.__ktLockedOut = true;
            if (_lk.switch_to) {
              try { sessionStorage.setItem('kt_active_agency_id', String(_lk.switch_to)); sessionStorage.removeItem('kt_active_agency_name'); } catch (e) {}
              window.location.reload();
            } else {
              const _tok = Auth.token();
              const _bye = function () {
                Auth.clear();
                try { sessionStorage.setItem('kt_locked_msg', _lk.message || ''); } catch (e) {}
                const _to = '/index.html?signed_out=locked';
                if (window.KT_out) window.KT_out.go(_to); else window.location.href = _to;
              };
              fetch(API_BASE + '/auth/logout', { method: 'POST', headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + _tok } })
                .then(_bye, _bye);
            }
          }
          throw new ApiError('access_locked', _lk.message || 'Your access is paused.', 423, _lk);
        }
      }

      // Auth expired? send to login
      if (res.status === 401) {
        Auth.clear();
        if (!window.location.pathname.endsWith('index.html')
            && window.location.pathname !== '/') {
          // Say why on the sign-in page - unless this is the sign-out itself.
          var _to = window.__ktLoggingOut ? '/index.html' : '/index.html?signed_out=expired';
          if (window.KT_out) window.KT_out.go(_to); else window.location.href = _to;
        }
        throw new ApiError('unauthorized', 'Session expired. Please sign in again.', 401);
      }

      // A successful WRITE means somebody's screen is now out of date — this
      // one, another screen, or the same user in another tab. Announce it from
      // the single place every request already passes through, so no screen has
      // to remember to do it. Reads say nothing and are ignored.
      if (method !== 'GET' && res.ok) {
        try {
          if (window.KT && KT.dataChanged
              && !(KT.liveRefresh && KT.liveRefresh.shouldIgnore(path))) {
            KT.dataChanged(path);
          }
        } catch (_) { /* never let this affect the request */ }
      }

      if (res.status === 204) return null;

      let data = null;
      try { data = await res.json(); } catch (_) {}

      if (!res.ok) {
        let msg = data?.message || data?.error || res.statusText;
        /* Never the server's raw words (2026-10-22): an empty body, "Server Error", a
           status text, or anything that reads like code becomes a plain sentence. */
        if (window.__ktCleanError) msg = window.__ktCleanError(msg, res.status);
        else if (!msg || res.status >= 500) msg = 'Something went wrong on our side. Please try again in a moment.';
        throw new ApiError(data?.error || 'error', msg, res.status, data);
      }

      return data;
    },
    /* TELL THE SERVER WHAT THE SERVER COULD NOT SEE.

       A 508 and a 421 never reach PHP, so nothing on the API can log them; between them
       they are 59,845 responses this month against 71 PHP-level 500s. The browser is the
       only witness, so it files the report.

       Rules, so the reporter never becomes the problem:
         · fire-and-forget — never awaited, never throws, never blocks a screen;
         · raw fetch, NOT Api.post, or a failing report would report its own failure
           and recurse;
         · at most one report per path+status per minute per tab, because a poller that
           is failing is failing every few seconds and would otherwise file hundreds;
         · silent when offline — there is nothing to send it to, and the browser has
           already told the user. */
    _reportFailure(path, method, status, ms, kind) {
      try {
        if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
        /* A request that never got an answer WHILE THE APP WAS IN THE BACKGROUND is the
           phone suspending it, not a fault. The audit on 2026-10-01 found 10 of these in
           two days (2.5 to 10+ minutes long, every one a background poll), filed as
           errors; four were even refused by the error endpoint for being over 10 minutes.
           Only status 0 is skipped — a 5xx that arrived is real whenever it arrived. */
        if (!status) {
          const startedAt = Date.now() - (ms || 0);
          if ((typeof document !== 'undefined' && document.hidden)
              || (Api._hiddenAt && Api._hiddenAt >= startedAt)
              // Woken just now and the 5s tick has not caught up yet (see the bottom of this file).
              || (Api._lastTick && Date.now() - Api._lastTick > 20000)) {
            return;
          }
        }
        const key = (path || '') + '|' + status;
        Api._seen = Api._seen || {};
        const now = Date.now();
        if (Api._seen[key] && now - Api._seen[key] < 60000) return;
        Api._seen[key] = now;

        const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        try {
          const t = Auth.token(); if (t) headers['Authorization'] = 'Bearer ' + t;
          const a = sessionStorage.getItem('kt_active_agency_id');
          if (a) headers['X-Active-Agency-Id'] = a;
        } catch (e) {}

        fetch(API_BASE + '/diag/client-error', {
          method: 'POST',
          headers,
          body: JSON.stringify({
            status: status,
            path: String(path || '').slice(0, 200),
            method: method || 'GET',
            kind: kind || null,
            ms: Math.min(ms || 0, 600000),
          }),
          keepalive: true,
        }).catch(() => {});
      } catch (e) { /* a failed report is not worth a second failure */ }
    },

    /* ONE REQUEST FOR THE SAME READ (2026-11-13, Anthony: "when the portal refreshes or saves that process moves very
       slow - full sweep"). On a refresh the shell, the top bar and the gates each asked for the same things at once -
       /auth/me, the MFA status, the markers and the activity feed all went twice - and the server, two cores, queued
       them behind everything else. A GET identical to one still on its way (same path, query, agency and view-as)
       now waits for that one instead of sending its own. Each extra caller gets its OWN copy of the answer, so a
       screen that changes what it was given cannot change another's. Finished requests are not kept: the next read
       always goes to the server. */
    get(path, query) {
      let key = null;
      try {
        key = path + '|' + (query ? JSON.stringify(query) : '') + '|' + (sessionStorage.getItem('kt_active_agency_id') || '')
          + '|' + (sessionStorage.getItem('kt_view_as') || '');
      } catch (e) { key = null; }
      const live = key && this._inflightGets && this._inflightGets.get(key);
      if (live) {
        return live.then(function (d) {
          if (d == null || typeof d !== 'object') return d;
          try { return (typeof structuredClone === 'function') ? structuredClone(d) : JSON.parse(JSON.stringify(d)); } catch (e) { return d; }
        });
      }
      const p = this.request(path, { query });
      if (key) {
        if (!this._inflightGets) this._inflightGets = new Map();
        const map = this._inflightGets;
        map.set(key, p);
        const done = function () { if (map.get(key) === p) map.delete(key); };
        p.then(done, done);
      }
      return p;
    },
    post(path, body) { return this.request(path, { method: 'POST', body }); },
    /** Multipart upload. Same as post() — request() detects FormData — but named
     *  so call sites reading as an upload are obvious. */
    postForm(path, formData) { return this.request(path, { method: 'POST', body: formData }); },
    patch(path, body) { return this.request(path, { method: 'PATCH', body }); },
    put(path, body) { return this.request(path, { method: 'PUT', body }); },
    delete(path) { return this.request(path, { method: 'DELETE' }); },
  };

  class ApiError extends Error {
    constructor(type, message, status, data) {
      super(message);
      this.type = type; this.status = status; this.data = data;
    }
  }

  // ─── Formatting ─────────────────────────────────────────────────
  const Fmt = {
    money(amount, currency = 'CAD') {
      const n = typeof amount === 'string' ? parseFloat(amount) : amount;
      if (!isFinite(n)) return '—';
      return new Intl.NumberFormat('en-CA', {
        style: 'currency', currency, currencyDisplay: 'symbol',
      }).format(n);
    },
    // Parse a server timestamp to a real instant. MySQL/PHP hand us UTC with NO
    // zone marker ("2026-08-05 13:30:04"); a bare new Date() reads that as the
    // browser's LOCAL time, so on anything behind UTC (all of North America) the
    // instant lands in the FUTURE → "just now" for events that are hours old.
    // This was the recurring notifications bug: individual screens appended 'Z'
    // but this shared Fmt never did, so every screen using Fmt stayed broken.
    // Date-only strings ("2026-08-05") are left as-is (already UTC midnight).
    parse(iso) {
      if (iso == null || iso === '') return null;
      if (iso instanceof Date) return isNaN(iso.getTime()) ? null : iso;
      let v = String(iso).trim();
      if (/^\d{4}-\d{2}-\d{2}$/.test(v)) { const d0 = new Date(v); return isNaN(d0.getTime()) ? null : d0; }
      v = v.replace(' ', 'T');
      if (!/(Z|[+-]\d{2}:?\d{2})$/.test(v)) v += 'Z';     // treat zone-less as UTC
      const d = new Date(v);
      return isNaN(d.getTime()) ? null : d;
    },
    // Render in the AGENCY's timezone (America/Toronto by default via kt-tz.js),
    // not the device's — a director on a phone set to another zone must still see
    // the centre's local time. Falls back to device tz only if kt-tz isn't loaded.
    time(iso) {
      const d = this.parse(iso);
      if (!d) return '';
      const z = (window.KT && KT.tz) ? KT.tz() : null;
      const opts = { hour: 'numeric', minute: '2-digit', hour12: true };
      if (z) opts.timeZone = z;
      // A US agency (kt-term.js -> kt_spelling) reads "3:27 PM"; everyone else keeps en-CA's "3:27 p.m." (2026-10-28).
      let loc = 'en-CA'; try { if (sessionStorage.getItem('kt_spelling') === 'us') loc = 'en-US'; } catch (e) {}
      return d.toLocaleTimeString(loc, opts);
    },
    date(iso, opts = { weekday: 'long', month: 'long', day: 'numeric' }) {
      const d = this.parse(iso);
      if (!d) return '';
      const z = (window.KT && KT.tz) ? KT.tz() : null;
      return d.toLocaleDateString('en-CA', z ? Object.assign({ timeZone: z }, opts) : opts);
    },
    relative(iso) {
      const d = this.parse(iso);
      if (!d) return '';
      let diff = (Date.now() - d.getTime()) / 1000;
      if (diff < 0) diff = 0;                              // clock skew → clamp, never negative
      if (diff < 60) return 'just now';
      if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
      if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
      if (diff < 86400 * 7) return Math.floor(diff / 86400) + 'd ago';
      return this.date(iso, { month: 'short', day: 'numeric' });
    },
    initials(name) {
      if (!name) return '?';
      const parts = name.trim().split(/\s+/);
      return (parts[0][0] + (parts[1]?.[0] || '')).toUpperCase();
    },
  };

  // ─── DOM helpers ────────────────────────────────────────────────
  const Dom = {
    $(sel, root = document) { return root.querySelector(sel); },
    $$(sel, root = document) { return Array.from(root.querySelectorAll(sel)); },
    el(tag, attrs = {}, ...children) {
      const node = document.createElement(tag);
      for (const [k, v] of Object.entries(attrs)) {
        if (k === 'class') node.className = v;
        else if (k === 'style' && typeof v === 'object') Object.assign(node.style, v);
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.substring(2).toLowerCase(), v);
        else if (k === 'html') node.innerHTML = v;
        else if (v === true) node.setAttribute(k, '');
        else if (v != null && v !== false) node.setAttribute(k, v);
      }
      for (const c of children.flat()) {
        if (c == null || c === false) continue;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
      }
      return node;
    },
    // Nothing to clear is not an error (ticket #185: a render after #appMain was removed threw here).
    clear(node) { if (!node) return; while (node.firstChild) node.removeChild(node.firstChild); },
    show(node) { node.hidden = false; },
    hide(node) { node.hidden = true; },
    toast(message, type = 'info') {
      // Unified toast: hand off to the canonical KT.toast (kt-toasts.js) so every
      // toast in the app shares ONE look (top-right rich card, mobile-aware) instead
      // of this bottom-centre pill. Map the simple type → icon + colour and call
      // KT.toast's explicit 4-arg form (no signature ambiguity). The local pill
      // below stays only as a fallback for when KT.toast hasn't loaded yet.
      try {
        if (window.KT && typeof window.KT.toast === 'function') {
          var _m = ({ info: ['ℹ️', '#1F6080'], success: ['✅', '#16A34A'], error: ['⚠️', '#DC2626'],
            warning: ['⚠️', '#D97706'], danger: ['⚠️', '#DC2626'] })[type] || ['ℹ️', '#1F6080'];
          return window.KT.toast(_m[0], message, '', _m[1]);
        }
      } catch (e) {}
      let toast = document.getElementById('kt-toast');
      if (!toast) {
        toast = document.createElement('div');
        toast.id = 'kt-toast';
        // Center transform-free (left/right + margin auto) and attach to <html>,
        // NOT <body>: a transformed ancestor (mobile screen transitions) turns a
        // transform-based fixed toast off-screen. Clear the bottom nav too.
        toast.style.cssText = `position:fixed; bottom:calc(var(--kt-safe-bottom, env(safe-area-inset-bottom,0px)) + 88px); left:0; right:0; margin:0 auto; width:max-content; max-width:calc(100vw - 24px);
          padding:12px 20px; border-radius:12px; font-size:14px; font-weight:500;
          z-index:2147483600; box-shadow:0 8px 32px rgba(0,0,0,0.18); transition:opacity 0.2s;`;
        (document.documentElement || document.body).appendChild(toast);
      }
      const colors = {
        info: ['#1F6080', '#FFFFFF'],
        success: ['#3DB6A0', '#FFFFFF'],
        error: ['#D85A6C', '#FFFFFF'],
      };
      const [bg, fg] = colors[type] || colors.info;
      toast.style.background = bg;
      toast.style.color = fg;
      toast.textContent = message;
      toast.style.opacity = '1';
      clearTimeout(toast._t);
      toast._t = setTimeout(() => { toast.style.opacity = '0'; }, 3500);
    },
  };

  // ─── Page bootstrap ─────────────────────────────────────────────
  // All authenticated pages call this on load to populate the nav
  async function bootstrapPage() {
    Auth.rememberSession();   // restore a persisted session (survives WebView restarts) before gating
    if (!Auth.requireLogin()) return null;

    /* Settle the role BEFORE anything reads it. Every screen and the whole nav branch on
       kt_view_as, so deciding it after the page has begun drawing would paint one role
       and then act as another. Only when nothing has set it already - a live session
       mid-preview must not be yanked back to the default on the next page load. */
    /* The role is settled at module scope now, not here - see the note beside
       rememberSession() at the foot of this file. bootstrapPage() is dead code. */

    const user = Auth.user();
    const navAvatar = Dom.$('#navAvatar');
    const navName = Dom.$('#navName');
    if (navAvatar) navAvatar.textContent = Fmt.initials(user?.name);
    if (navName) navName.textContent = user?.name?.split(' ')[0] || 'You';

    // Click on avatar opens menu
    const navUser = Dom.$('#navUser');
    if (navUser) {
      navUser.addEventListener('click', async () => {
        if (await KT.confirm('Sign out of Kiddietrac?')) Auth.logout();
      });
    }

    // Periodically refresh notification badge
    refreshNotificationBadge();
    setInterval(refreshNotificationBadge, 15_000);   // was 60s: the badge trailed reality by a minute

    return user;
  }

  async function refreshNotificationBadge() {
    try {
      const { unread_count } = await Api.get('/notifications', { unread_only: 1 }) || {};
      const badge = Dom.$('#navMsgBadge');
      if (!badge) return;
      if (unread_count > 0) {
        badge.textContent = unread_count;
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
    } catch (_) { /* silent */ }
  }

  // When the page last went to the background — see _reportFailure.
  try {
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { Api._hiddenAt = Date.now(); }
    });
  } catch (_) {}
  /* THE ANDROID APP NEVER SAYS IT WENT TO SLEEP (2026-10-19). Every "request never
     completed" in the audit log that day (12 of them, 5 to 10 minutes long) came from the
     app's WebView ("; wv)"), where document.hidden stays false when the phone locks or the
     app is put away, so the check above never ran. Two signals that do work there: the
     native shell's pause/resume events, and the clock itself — a timer that should tick
     every 5s and instead wakes 20s+ later means this page was frozen in between. Either
     marks the page as having been asleep, and _reportFailure skips what was in flight. */
  try {
    ['pause', 'resume', 'freeze', 'pagehide'].forEach(function (ev) {
      document.addEventListener(ev, function () { Api._hiddenAt = Date.now(); });
    });
    Api._lastTick = Date.now();
    setInterval(function () {
      var now = Date.now();
      if (now - Api._lastTick > 20000) { Api._hiddenAt = now; }
      Api._lastTick = now;
    }, 5000);
  } catch (_) {}

  // Export
  /* markSignedOut / ownsSharedSession ride along (2026-11-13): this export REPLACES window.KT, so the two set
     earlier in the file were dropped and session-timeout / kt-topbar's "KT.markSignedOut &&" quietly skipped the
     sign-out mark since 2026-10-21. */
  window.KT = { Auth, Api, ApiError, Fmt, Dom, bootstrapPage, API_BASE, markSignedOut, ownsSharedSession };

  /* KEEP THE SESSION ALIVE ACROSS AN APP RESTART — CALLED HERE, ON EVERY PAGE.

     rememberSession() is what mirrors the token into localStorage so it survives the
     WebView being killed, and restores it on a cold launch. It had exactly one caller,
     bootstrapPage(), and NOTHING CALLS bootstrapPage — it is defined, exported, and dead.
     The dashboard boots through app-v2-shell.js instead. So the mirror never ran and the
     token lived only in sessionStorage, which Android discards whenever it reclaims the
     app: for an educator with the phone in a pocket between nappy changes, that is every
     few minutes.

     It is why educators were signing in again and again while the session timeout said
     sixteen hours. The timeout was never reached — there was no session left to time out.
     Measured on 2026-09-09: three educators logged in 4-5 times each in an afternoon, one
     pair of logins fourteen minutes apart, and NOT ONE logout event in the audit log,
     because nobody ever logged out.

     Called at module scope rather than from a boot function, so it cannot be orphaned
     again by a page that boots some other way. It is cheap, idempotent, and self-guarding:
     it does nothing when there is no token, and it deliberately stands aside when a
     biometric or PIN lock is enrolled, because those own their own encrypted vault and
     must not be bypassed. (Anthony, 2026-09-09) */
  try { Auth.rememberSession(); } catch (e) {}

  /* SETTLE THE VIEW-AS ROLE HERE TOO, AND FOR THE SAME REASON.

     I first called applyViewAsPreference() from bootstrapPage(), which is precisely the
     mistake the note above is about: nothing calls bootstrapPage, so the preference was
     stored correctly and then never read on a normal sign-in. Verified in the browser -
     kt_view_as_pref held {"uid":1,"role":"guardian"} and kt_view_as came back null.

     Module scope runs while app.js is parsed, before app-v2-shell.js boots the dashboard,
     so the role is decided before the nav or any screen branches on it.

     Only when nothing has set it already: a live session mid-preview must not be yanked
     back on the next page load. (2026-09-17) */
  try {
    var _hasView = false;
    try { _hasView = sessionStorage.getItem('kt_view_as') !== null; } catch (e) {}
    if (!_hasView) { Auth.applyViewAsPreference(); }
  } catch (e) {}
})(window);
