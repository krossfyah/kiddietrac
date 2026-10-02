// KiddieTrac Training — narrated tutorial videos from scripts (2026-10-01).
//
//   node tut.js explore <account> <#hash> [phone|desktop]   screenshot + list of tappable texts
//   node tut.js make scripts/<audience>/<slug>.json [...]   narrate → record → mix → upload as DRAFT
//
// Recording happens in a separate headless Chrome profile on the Test Agency with demo
// accounts, and every real person is scrubbed from the page (../demo/harness.js SCRUB).
// A short-lived login is minted on the server per run and revoked at the end.
const puppeteer = require('../demo/node_modules/puppeteer-core');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync, execSync } = require('child_process');
const { SCRUB } = require('../demo/harness');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const FF = path.resolve(__dirname, '../vid/node_modules/@ffmpeg-installer/win32-x64/ffmpeg.exe');
const API = 'https://api.kiddietrac.com/api/v1';
const APP = 'https://app.kiddietrac.com';
const SSH = ['-i', process.env.HOME + '/.ssh/kiddietrac_id', '-o', 'UserKnownHostsFile=' + process.env.HOME + '/.ssh/kiddietrac_known_hosts'];
const HOST = 'k3l6yt3xmih3@p3plzcpnl505449.prod.phx3.secureserver.net';
const VOICE = 'Azure.en-CA-ClaraNeural';
const ACCOUNTS = { parent: 94, educator: 84, home_visitor: 232, director: 100 };  // director = Rebecca Bright, centre 18            // Test Agency demo accounts only
const GAP = 0.55;                                         // seconds of quiet between sentences
// This process's own debug-login name, so two batches never revoke each other's login.
const RUN_TOKEN = 'claude-debug-' + process.pid;
const OUT = path.join(__dirname, 'out');
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

function ssh(cmd) {
  return execFileSync('ssh', [...SSH, HOST, cmd], { encoding: 'utf8', stdio: ['pipe', 'pipe', 'ignore'] });
}
function scpTo(local, remote) { execFileSync('scp', ['-q', ...SSH, local, HOST + ':' + remote], { stdio: 'ignore' }); }
function scpFrom(remote, local) { execFileSync('scp', ['-q', ...SSH, HOST + ':' + remote, local], { stdio: 'ignore' }); }
function php(code) {
  // Unique per call: two runs at once must never execute each other's script.
  const id = 'kt_tut_' + process.pid + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
  const f = path.join(os.tmpdir(), id + '.php');
  fs.writeFileSync(f, '<?php\nchdir(getenv("HOME")."/kiddietrac/backend"); require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\nuse Illuminate\\Support\\Facades\\DB;\n' + code);
  scpTo(f, '/tmp/' + id + '.php');
  try { fs.unlinkSync(f); } catch (e) {}
  return ssh('php /tmp/' + id + '.php; rm -f /tmp/' + id + '.php').trim();
}
function mint(userId, name) {
  return php(`echo App\\Models\\User::find(${userId})->createToken("${name || 'claude-debug'}", ["*"], now()->addMinutes(60))->plainTextToken;`).split('\n').pop().trim();
}
function revoke(name) { try { php(`echo DB::table("personal_access_tokens")->where("name","${name || "claude-debug"}")->delete();`); } catch (e) {} }

/* ── in-page helpers: a visible finger/cursor, tap ripple, highlight ring, find-by-text ── */
const TUT = '(' + function () {
  if (window.__tut) return;
  var cur;
  function el() {
    if (cur && cur.isConnected) return cur;
    cur = document.createElement('div');
    cur.id = '__tut_cursor';
    cur.style.cssText = 'position:fixed;left:-80px;top:-80px;width:30px;height:30px;margin:-15px 0 0 -15px;border-radius:50%;'
      + 'background:rgba(14,124,144,.28);border:2.5px solid #fff;box-shadow:0 0 0 2.5px rgba(14,124,144,.95),0 6px 16px rgba(0,0,0,.35);'
      + 'z-index:2147483647;pointer-events:none;transition:left .6s cubic-bezier(.4,0,.2,1),top .6s cubic-bezier(.4,0,.2,1);';
    document.documentElement.appendChild(cur);
    return cur;
  }
  function visible(n) {
    if (!n || !n.getBoundingClientRect) return false;
    var r = n.getBoundingClientRect(), s = getComputedStyle(n);
    return r.width > 2 && r.height > 2 && s.visibility !== 'hidden' && s.display !== 'none' && +s.opacity !== 0;
  }
  function find(q) {
    if (q.indexOf('text:') !== 0) { var all0 = Array.prototype.slice.call(document.querySelectorAll(q)).filter(visible); return all0[0] || null; }
    var want = q.slice(5).trim().toLowerCase(), best = null, bestLen = 1e9;
    var all = document.querySelectorAll('button,a,[role=button],[role=tab],label,summary,select,input,h1,h2,h3,h4,li,th,td,span,div,p');
    for (var i = 0; i < all.length; i++) {
      var n = all[i]; if (n.closest('#__tut_cursor')) continue;
      var t = (n.innerText || n.value || n.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().toLowerCase();
      if (!t || t.length > 140) continue;
      var hit = t === want ? 0 : (t.indexOf(want) !== -1 ? 1 : -1);
      if (hit < 0 || !visible(n)) continue;
      // Something on top of it (an overlay) means it is not what the viewer sees: rank it last.
      var rc = n.getBoundingClientRect(), cx = rc.left + rc.width / 2, cy = rc.top + rc.height / 2, onTop = 0;
      // Off-screen is not covered: it only needs scrolling to. Rank last only what is under something.
      if (cx >= 0 && cy >= 0 && cx <= innerWidth && cy <= innerHeight) { var at = document.elementFromPoint(cx, cy); onTop = at && (n === at || n.contains(at) || at.contains(n)) ? 0 : 1; }
      else if (document.querySelector('[role=dialog],.kt-modal-overlay') && !n.closest('[role=dialog],.kt-modal-overlay')) onTop = 1;
      var clickable = n.matches('button,a,[role=button],[role=tab],label,summary,select,input') ? 0 : 1;
      var score = onTop * 100000 + hit * 1000 + t.length * 2 + clickable;   // on top, exact, tightest, then a real control
      if (score < bestLen) { bestLen = score; best = n; }
    }
    if (!best) return null;
    var c = best.closest('button,a,[role=button],[role=tab],label,summary,select,input');
    if (c && visible(c)) return c;
    // A wrapper with the same text as the control inside it: use the control.
    var inner = best.querySelectorAll('button,a,[role=button],[role=tab],label,summary,select,input');
    return inner.length === 1 && visible(inner[0]) ? inner[0] : best;
  }
  window.__tut = {
    point: function (q) {
      var n = find(q); if (!n) return null;
      n.scrollIntoView({ block: 'center', inline: 'nearest' });
      var r = n.getBoundingClientRect();
      var x = r.left + r.width / 2, y = r.top + r.height / 2;
      window.__tutLast = n;
      var c = el(); c.style.left = x + 'px'; c.style.top = y + 'px';
      return { x: x, y: y, w: r.width, h: r.height };
    },
    moveTo: function (x, y) { var c = el(); c.style.left = x + 'px'; c.style.top = y + 'px'; },
    hide: function () { if (cur) { cur.style.left = "-80px"; cur.style.top = "-80px"; } },
    ripple: function (x, y) {
      var d = document.createElement('div');
      d.style.cssText = 'position:fixed;left:' + x + 'px;top:' + y + 'px;width:12px;height:12px;margin:-6px 0 0 -6px;border-radius:50%;'
        + 'border:3px solid rgba(14,124,144,.9);z-index:2147483646;pointer-events:none;transition:all .55s ease-out;';
      document.documentElement.appendChild(d);
      requestAnimationFrame(function () { d.style.width = d.style.height = '64px'; d.style.margin = '-32px 0 0 -32px'; d.style.opacity = '0'; });
      setTimeout(function () { d.remove(); }, 700);
    },
    ring: function (q, ms) {
      var n = find(q); if (!n) return false;
      n.scrollIntoView({ block: 'center', inline: 'nearest' });
      var r = n.getBoundingClientRect();
      var d = document.createElement('div');
      d.style.cssText = 'position:fixed;left:' + (r.left - 6) + 'px;top:' + (r.top - 6) + 'px;width:' + (r.width + 12) + 'px;height:' + (r.height + 12) + 'px;'
        + 'border:3px solid #F59E0B;border-radius:12px;box-shadow:0 0 0 4px rgba(245,158,11,.25);z-index:2147483646;pointer-events:none;';
      document.documentElement.appendChild(d);
      setTimeout(function () { d.remove(); }, ms || 1800);
      return true;
    },
    scroll: function (px, ms) {
      var cands = [document.getElementById('appMain'), document.scrollingElement].filter(Boolean);
      var s = cands.find(function (e) { return e.scrollHeight > e.clientHeight + 20; }) || document.scrollingElement;
      var from = s.scrollTop, t0 = performance.now();
      return new Promise(function (res) {
        (function step(now) {
          var k = Math.min(1, (now - t0) / ms), e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
          s.scrollTop = from + px * e;
          if (k < 1) requestAnimationFrame(step); else res();
        })(t0);
      });
    },
    texts: function () {
      var out = [], seen = {};
      document.querySelectorAll('button,a,[role=button],[role=tab],label,summary,select,input,h1,h2,h3').forEach(function (n) {
        if (!visible(n)) return;
        var t = (n.innerText || n.value || n.getAttribute('aria-label') || n.getAttribute('placeholder') || '').replace(/\s+/g, ' ').trim();
        if (!t || t.length > 80 || seen[t]) return; seen[t] = 1;
        out.push(n.tagName.toLowerCase() + ': ' + t);
      });
      return out;
    },
  };
}.toString() + ')();';

// Hide what is not part of the lesson: onboarding/biometric gates, the alerts nudge, install banners.
const QUIET_CSS = '#kt-agree,#kt-bio-ov,#kt-urgent,#kt-mfa-gate,#kt-push-nudge,#kt-install-banner,#kt-diag-chip{display:none!important}';

async function openAs(b, account, device, token) {
  const page = await b.newPage();
  await page.evaluateOnNewDocument(SCRUB);
  await page.evaluateOnNewDocument(TUT);
  await page.evaluateOnNewDocument((css) => {
    document.addEventListener('DOMContentLoaded', () => { const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s); });
    // A hidden gate still makes the page inert (a demo account that has not signed the
    // terms could not type into anything). Release it while the gate is hidden.
    setInterval(() => {
      if (!document.querySelector('#kt-agree,#kt-bio-ov,#kt-urgent,#kt-mfa-gate')) return;
      document.querySelectorAll('[inert]').forEach((e) => { if (!e.closest('#kt-agree,#kt-bio-ov,#kt-urgent,#kt-mfa-gate')) e.removeAttribute('inert'); });
    }, 300);
    try { localStorage.setItem('kt_tour_done', '1'); localStorage.setItem('kt_welcome_seen', '1'); localStorage.setItem('kt_push_nudge_until', String(Date.now() + 864e5)); sessionStorage.setItem('kt_install_dismissed', '1'); sessionStorage.setItem('kt_ios_install_dismissed', '1'); } catch (e) {}
  }, QUIET_CSS + (account === 'director' ? '#kt-chat-dock{display:none!important}' : ''));  // the director's chat window would cover every screen (and real names)
  if (device === 'phone') {
    await page.emulate({ viewport: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
      userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36' });
  } else {
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    const c = await page.target().createCDPSession();
    await c.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false, screenWidth: 1440, screenHeight: 900 });
  }
  await page.goto(APP + '/index.html', { waitUntil: 'domcontentloaded' });
  const u = await page.evaluate(async (API, tok) => {
    const r = await (await fetch(API + '/auth/me', { headers: { Authorization: 'Bearer ' + tok, Accept: 'application/json' } })).json();
    return r.user || r;
  }, API, token);
  await page.evaluate((tok, u) => {
    sessionStorage.setItem('kt_token', tok); sessionStorage.setItem('kt_user', JSON.stringify(u));
    sessionStorage.setItem('kt_active_agency_id', '6'); sessionStorage.setItem('kt_active_agency_name', 'Test Agency');
  }, token, u);
  return page;
}

async function go(page, hash, wait) {
  await page.goto(APP + '/dashboard.html' + hash, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
  await sleep(wait || 6000);
}

async function browser() {
  return puppeteer.launch({ executablePath: CHROME, headless: true, pipe: true, protocolTimeout: 90000, userDataDir: fs.mkdtempSync(path.join(os.tmpdir(), 'kttut-')),
    args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions', '--hide-scrollbars', '--lang=en-CA', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--use-file-for-fake-video-capture=' + path.join(__dirname, 'assets', 'poster.y4m').split(path.sep).join('/')] });
}

/* ── one action ─────────────────────────────────────────────────── */
async function act(page, a, device) {
  if (a.wait) { await sleep(a.wait); return; }
  if (a.go) { await page.evaluate((h) => { location.hash = h; }, a.go); await sleep(a.settle || 2200); return; }
  if (a.scroll) { await page.evaluate((px, ms) => window.__tut.scroll(px, ms), a.scroll, a.ms || 1400); return; }
  if (a.ring) { const ok = await page.evaluate((q, ms) => window.__tut.ring(q, ms), a.ring, a.ms || 1800); if (!ok) console.log('   ! ring not found:', a.ring); await sleep(a.ms || 1800); return; }
  if (a.point) { const p = await page.evaluate((q) => window.__tut.point(q), a.point); if (!p) console.log('   ! point not found:', a.point); await sleep(900); return; }
  if (a.tap || a.type) {
    const q = a.tap || a.type[0];
    const p = await page.evaluate((q) => window.__tut.point(q), q);
    if (!p) { console.log('   ! not found:', q); return; }
    await sleep(700);                                                   // the finger travels there first
    await page.evaluate((x, y) => window.__tut.ripple(x, y), p.x, p.y);
    if (device === 'phone') {
      // Click the element the finger is on. Simulated touches are swallowed by screens
      // that stop touch events (the check-in scanner), and stall under a camera stream.
      await page.evaluate(() => { const n = window.__tutLast; if (!n) return; if (n.matches('input,textarea,select,[contenteditable=true]')) n.focus(); n.click(); });
    } else await page.mouse.click(p.x, p.y);
    if (a.type) { await sleep(250); await page.keyboard.type(a.type[1], { delay: 55 }); }
    await sleep(a.settle || 900);
    await page.evaluate(() => window.__tut.hide());
    return;
  }
  if (a.key) { await page.keyboard.press(a.key); await sleep(400); return; }
  if (a.typeAt) {
    const [sel, idx, text] = a.typeAt;
    const p = await page.evaluate((sel, idx) => {
      const vis = (n) => { const r = n.getBoundingClientRect(); return r.width > 2 && r.height > 2; };
      const inOverlay = (n) => { for (let e = n; e && e !== document.body; e = e.parentElement) { const s = getComputedStyle(e); if (s.position === 'fixed' && (+s.zIndex || 0) > 1000) return true; } return false; };
      // Inside an open dialog if there is one; otherwise the page itself.
      let els = Array.from(document.querySelectorAll(sel)).filter((n) => vis(n) && inOverlay(n));
      if (!els.length) els = Array.from(document.querySelectorAll(sel)).filter(vis);
      const n = els[idx]; if (!n) return null;
      n.scrollIntoView({ block: 'center', inline: 'nearest' });
      const r = n.getBoundingClientRect(); const x = r.left + Math.min(r.width / 2, 70), y = r.top + r.height / 2;
      window.__tut.moveTo(x, y); return { x, y };
    }, sel, idx);
    if (!p) { console.log('   ! typeAt not found:', sel, idx); return; }
    await sleep(650);
    await page.evaluate((x, y) => window.__tut.ripple(x, y), p.x, p.y);
    if (device === 'phone') await page.touchscreen.tap(p.x, p.y); else await page.mouse.click(p.x, p.y);
    await sleep(250); await page.keyboard.type(text, { delay: 45 });
    await sleep(a.settle || 500);
    await page.evaluate(() => window.__tut.hide());
    return;
  }
  if (a.draw) {
    // A signature: a few connected strokes across the pad, drawn at finger speed.
    const box = await page.evaluate((q) => { const vis = (c) => { const r = c.getBoundingClientRect(); return r.width > 20 && r.height > 20; }; const n = q === 'canvas:last' ? Array.from(document.querySelectorAll('canvas')).filter(vis).pop() : document.querySelector(q); if (!n) return null; n.scrollIntoView({ block: 'center' }); const r = n.getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; }, a.draw);
    if (!box) { console.log('   ! draw target not found:', a.draw); return; }
    await page.evaluate((x, y) => window.__tut.point && window.__tut.ripple(x, y), box.x + box.w * 0.2, box.y + box.h * 0.55);
    const pts = [];
    for (let i = 0; i <= 40; i++) { const t = i / 40; pts.push([box.x + box.w * (0.15 + 0.7 * t), box.y + box.h * (0.55 + 0.18 * Math.sin(t * Math.PI * 3))]); }
    await page.mouse.move(pts[0][0], pts[0][1]); await page.mouse.down();
    for (const [x, y] of pts) { await page.mouse.move(x, y, { steps: 2 }); await sleep(14); }
    await page.mouse.up(); await sleep(a.settle || 700);
    return;
  }
  if (a.upload) {
    // [selector of the file input, local file] — the picker itself cannot be filmed.
    const h = await page.$(a.upload[0]);
    if (!h) { console.log('   ! upload input not found:', a.upload[0]); return; }
    await h.uploadFile(path.resolve(__dirname, a.upload[1]));
    await sleep(a.settle || 1500);
    return;
  }
  if (a.choose) {
    // [selector of a <select>, visible option text]
    const ok = await page.evaluate((q, label) => {
      // The dropdown that actually offers this option (pages have several selects).
      const want = (o) => o.textContent.trim().toLowerCase().indexOf(label.toLowerCase()) !== -1;
      const vis = (e) => { const r = e.getBoundingClientRect(); return r.width > 2 && r.height > 2; };
      const s = Array.from(document.querySelectorAll(q)).find((x) => vis(x) && Array.from(x.options).some(want)); if (!s) return false;
      window.__tutLastSelect = s;
      const o = Array.from(s.options).find(want);
      s.scrollIntoView({ block: 'center' }); s.value = o.value; s.dispatchEvent(new Event('input', { bubbles: true })); s.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }, a.choose[0], a.choose[1]);
    if (!ok) console.log('   ! choose failed:', JSON.stringify(a.choose));
    await page.evaluate(() => { const s = window.__tutLastSelect; if (s) { s.setAttribute('data-tut-sel', '1'); window.__tut.ring('[data-tut-sel="1"]', 1200); s.removeAttribute('data-tut-sel'); } });
    await sleep(a.settle || 1300);
    return;
  }
  if (a.eval) { await page.evaluate(a.eval); await sleep(a.settle || 500); return; }
  console.log('   ! unknown action', JSON.stringify(a));
}

function duration(file) {
  let s = '';
  try { execFileSync(FF, ['-hide_banner', '-i', file], { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }); } catch (e) { s = String(e.stderr || ''); }
  const m = /Duration: (\d+):(\d+):([\d.]+)/.exec(s);
  return m ? (+m[1]) * 3600 + (+m[2]) * 60 + parseFloat(m[3]) : 0;
}
const vttTime = (t) => { const h = Math.floor(t / 3600), m = Math.floor(t % 3600 / 60), s = (t % 60).toFixed(3).padStart(6, '0'); return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + s; };
const ff = (args) => execFileSync(FF, ['-y', '-loglevel', 'error', ...args], { stdio: 'inherit', timeout: 300000 });

/* ── narrate (server-side TTS, one file per sentence so captions are exact) ── */
function narrate(sc, dir) {
  const lines = sc.steps.map(s => s.say);
  const key = sc.audience + '__' + sc.slug;
  const allThere = lines.every((l, i) => fs.existsSync(path.join(dir, `${i}.mp3`)) && fs.readFileSync(path.join(dir, `${i}.txt`), 'utf8') === l);
  if (!allThere) {
    const j = path.join(os.tmpdir(), key + '.json');
    fs.writeFileSync(j, JSON.stringify({ voice: VOICE, [key]: lines }));
    scpTo(j, '/tmp/' + key + '.json');
    scpTo(path.join(__dirname, '../demo/audio/tts.php'), '/tmp/kt_tts_' + process.pid + '.php');
    ssh(`mkdir -p /tmp/kt_tts_${key} && php /tmp/kt_tts_${process.pid}.php /tmp/${key}.json /tmp/kt_tts_${key} && cd /tmp/kt_tts_${key} && tar czf /tmp/${key}.tgz *.mp3`);
    const tgz = path.join(os.tmpdir(), key + '.tgz');
    scpFrom('/tmp/' + key + '.tgz', tgz);
    execSync(`tar --force-local -xzf "${tgz.replace(/\\/g, '/')}" -C "${dir.replace(/\\/g, '/')}"`);
    ssh(`rm -rf /tmp/kt_tts_${key} /tmp/${key}.json /tmp/${key}.tgz /tmp/kt_tts_${process.pid}.php`);
    lines.forEach((l, i) => {
      fs.renameSync(path.join(dir, `${key}-${i}.mp3`), path.join(dir, `${i}.mp3`));
      fs.writeFileSync(path.join(dir, `${i}.txt`), l);
    });
  }
  let t = 0.8;
  const cues = lines.map((text, i) => {
    const d = duration(path.join(dir, `${i}.mp3`));
    const c = { start: +t.toFixed(2), end: +(t + d).toFixed(2), text, hold: sc.steps[i].hold || 0 };
    t += d + GAP + (sc.steps[i].hold || 0);
    return c;
  });
  return { cues, total: +(t + 0.6).toFixed(2) };
}

/* ── record, paced to the narration ─────────────────────────────── */
async function record(sc, cues, total, dir, token) {
  const b = await browser();
  try {
    const page = await openAs(b, sc.account, sc.device, token);
    await go(page, sc.start, sc.warm || 7000);
    for (const a of (sc.before || [])) { await act(page, a, sc.device); }
    const webm = path.join(dir, 'raw.webm');
    const rec = await page.screencast({ path: webm, ffmpegPath: FF });
    const t0 = Date.now();
    for (let i = 0; i < sc.steps.length; i++) {
      const wait = cues[i].start * 1000 - (Date.now() - t0);
      if (wait > 0) await sleep(wait); else if (wait < -400) console.log(`   ! step ${i} started ${(-wait / 1000).toFixed(1)}s late`);
      for (const a of (sc.steps[i].do || [])) { await act(page, a, sc.device); }
    }
    const rest = total * 1000 + 500 - (Date.now() - t0);
    if (rest > 0) await sleep(rest);
    await rec.stop();
    await page.close();
  } finally { await b.close(); }
}

/* ── mix voice onto the picture, captions, poster, chapters ─────── */
function mix(sc, cues, total, dir) {
  const d = String(total);
  const vIn = ['-f', 'lavfi', '-t', d, '-i', 'anullsrc=r=48000:cl=stereo'];
  cues.forEach((c, i) => vIn.push('-i', path.join(dir, `${i}.mp3`)));
  const vf = cues.map((c, i) => `[${i + 1}:a]aresample=48000,aformat=channel_layouts=stereo,adelay=${Math.round(c.start * 1000)}|${Math.round(c.start * 1000)}[s${i}]`);
  vf.push(`[0:a]${cues.map((c, i) => `[s${i}]`).join('')}amix=inputs=${cues.length + 1}:duration=first:dropout_transition=0,volume=${cues.length + 1}[vo]`);
  ff([...vIn, '-filter_complex', vf.join(';'), '-map', '[vo]', '-t', d, path.join(dir, 'voice.wav')]);
  ff(['-i', path.join(dir, 'voice.wav'), '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11', '-ar', '48000', '-ac', '2', '-t', d, path.join(dir, 'final.wav')]);
  const scale = sc.device === 'phone' ? 'scale=540:-2' : 'scale=1280:-2';
  ff(['-i', path.join(dir, 'raw.webm'), '-i', path.join(dir, 'final.wav'), '-vf', `${scale},fps=25,format=yuv420p`,
    '-map', '0:v', '-map', '1:a', '-t', d, '-c:v', 'libx264', '-crf', '26', '-preset', 'slow', '-c:a', 'aac', '-b:a', '112k',
    '-movflags', '+faststart', path.join(dir, 'video.mp4')]);
  ff(['-ss', String(sc.posterAt || 2.0), '-i', path.join(dir, 'video.mp4'), '-frames:v', '1', '-q:v', '3', path.join(dir, 'poster.jpg')]);
  fs.writeFileSync(path.join(dir, 'captions.vtt'), 'WEBVTT\n\n' + cues.map((c, i) => `${i + 1}\n${vttTime(c.start)} --> ${vttTime(c.end + 0.3)}\n${c.text}\n`).join('\n'));
  // Review sheet: one frame a second, so every frame can be checked for leaks before publishing.
  const frames = path.join(dir, 'frames'); fs.mkdirSync(frames, { recursive: true });
  for (const f of fs.readdirSync(frames)) fs.unlinkSync(path.join(frames, f));
  ff(['-i', path.join(dir, 'video.mp4'), '-vf', 'fps=1/2,scale=270:-2,tile=6x3', path.join(frames, 'sheet-%02d.jpg')]);
  return sc.steps.map((s, i) => s.chapter ? { t: Math.max(0, Math.floor(cues[i].start - 0.3)), label: s.chapter } : null).filter(Boolean);
}

/* ── upload + register as DRAFT ─────────────────────────────────── */
function publishDraft(sc, dir, chapters, total) {
  const base = `/training-media/${sc.audience}/${sc.slug}`;
  ssh(`mkdir -p ~/kiddietrac/parent-portal/training-media/${sc.audience}`);
  const v = Date.now().toString(36);
  for (const [f, ext] of [['video.mp4', 'mp4'], ['captions.vtt', 'vtt'], ['poster.jpg', 'jpg']]) {
    scpTo(path.join(dir, f), `kiddietrac/parent-portal${base}.${ext}`);
  }
  const row = { audience: sc.audience, slug: sc.slug, title: sc.title, video_url: `${base}.mp4?v=${v}`, captions_url: `${base}.vtt?v=${v}`,
    poster_url: `${base}.jpg?v=${v}`, duration_sec: Math.round(total), chapters: JSON.stringify(chapters) };
  const out = php(`$r = json_decode(base64_decode("${Buffer.from(JSON.stringify(row)).toString('base64')}"), true);
$was = DB::table("training_videos")->where(["audience" => $r["audience"], "slug" => $r["slug"]])->first();
$r["status"] = "draft"; $r["recorded_at"] = now(); $r["updated_at"] = now(); $r["published_at"] = null; $r["published_by"] = null;
if ($was) { DB::table("training_videos")->where("id", $was->id)->update($r); echo "updated #", $was->id; }
else { $r["created_at"] = now(); echo "created #", DB::table("training_videos")->insertGetId($r); }`);
  return out.split('\n').pop();
}

/* ── remove what a recording created (declared per script) ──────── */
function cleanup(sc, sinceUtc) {
  if (!sc.cleanup || !sc.cleanup.length) return '';
  const spec = Buffer.from(JSON.stringify({ since: sinceUtc, rules: sc.cleanup, user: ACCOUNTS[sc.account] })).toString('base64');
  return php(`$s = json_decode(base64_decode("${spec}"), true); $n = [];
foreach ($s["rules"] as $r) {
  // Only rows the DEMO account made during this recording: the user column must match it.
  if (empty($r["table"]) || empty($r["by"])) continue;
  $n[] = $r["table"] . ":" . DB::table($r["table"])->where($r["by"], $r["value"] ?? $s["user"])->where("created_at", ">=", $s["since"])->delete();
}
echo implode(" ", $n);`);
}

async function make(file) {
  const sc = JSON.parse(fs.readFileSync(file, 'utf8'));
  const dir = path.join(OUT, sc.audience, sc.slug); fs.mkdirSync(dir, { recursive: true });
  console.log(`▶ ${sc.audience}/${sc.slug} — ${sc.title}`);
  const { cues, total } = narrate(sc, dir);
  console.log(`   narration ${total}s, ${cues.length} lines`);
  const since = php('echo now()->format("Y-m-d H:i:s");').split('\n').pop();
  /* setup: server-side PHP that creates the demo data the lesson needs (a task, an
     award...) and echoes a JSON object of what it made. teardown gets that object back
     as $ctx, and runs whether or not the recording succeeded. */
  let ctx = {};
  if (sc.setup) {
    const out = php(sc.setup.join('\n')).split('\n').pop();
    try { ctx = JSON.parse(out); } catch (e) { throw new Error('setup did not echo JSON: ' + out); }
    console.log('   setup', JSON.stringify(ctx));
  }
  const token = mint(ACCOUNTS[sc.account], RUN_TOKEN);
  if (Object.keys(ctx).length) { let j = JSON.stringify(sc.steps); for (const k of Object.keys(ctx)) j = j.split('{{' + k + '}}').join(String(ctx[k])); sc.steps = JSON.parse(j); }
  try { await record(sc, cues, total, dir, token); } finally {
    revoke(RUN_TOKEN);
    if (sc.teardown) {
      const out = php('$ctx = json_decode(base64_decode("' + Buffer.from(JSON.stringify(ctx)).toString('base64') + '"), true);\n' + sc.teardown.join('\n'));
      console.log('   teardown', out.split('\n').pop());
    }
  }
  const cl = cleanup(sc, since); if (cl) console.log('   cleanup', cl);
  const chapters = mix(sc, cues, total, dir);
  const reg = publishDraft(sc, dir, chapters, total);
  console.log(`   uploaded, ${reg} (draft), ${(fs.statSync(path.join(dir, 'video.mp4')).size / 1048576).toFixed(1)} MB`);
}

async function explore(account, hashes, device) {
  const token = mint(ACCOUNTS[account], "claude-debug-explore-" + process.pid);
  const b = await browser();
  try {
    const page = await openAs(b, account, device || "phone", token);
    for (const spec of hashes.split("||")) {
    const [hash, ...taps] = spec.split(">");
    await go(page, hash, 7000);
    for (const t of taps) {
      if (t.indexOf("jsfile:") === 0) { console.log("js ->", JSON.stringify(await page.evaluate(fs.readFileSync(t.slice(7), "utf8")))); await sleep(1500); }
      else if (t.indexOf("js:") === 0) { console.log("js ->", JSON.stringify(await page.evaluate(t.slice(3)))); await sleep(2500); }
      else if (t.indexOf("type:") === 0) { const [sel, txt] = t.slice(5).split("="); await act(page, { type: [sel, txt], settle: 800 }, device || "phone"); }
      else await act(page, { tap: t, settle: 2500 }, device || "phone");
    }
    console.log('\n===== ' + hash);
    const shot = path.join(OUT, 'explore-' + account + '-' + hash.replace(/[^a-z0-9]+/gi, '_') + '.png');
    fs.mkdirSync(OUT, { recursive: true });
    await page.screenshot({ path: shot, fullPage: false });
    const texts = await page.evaluate(() => window.__tut.texts());
    console.log('hash now:', await page.evaluate(() => location.hash));
    console.log(texts.join('\n'));
    console.log('screenshot:', shot);
    }
  } finally { await b.close(); revoke("claude-debug-explore-" + process.pid); }
}

(async () => {
  const [cmd, ...args] = process.argv.slice(2);
  if (cmd === 'explore') await explore(args[0], args[1], args[2]);
  else if (cmd === 'make') {
    // One video failing must not stop the rest (its teardown has already run in make()).
    for (const f of args) { try { await make(f); } catch (e) { console.log('ERR', f, e.message); revoke(RUN_TOKEN); } }
  }
  else console.log('usage: node tut.js explore <account> <#hash> [phone|desktop] | make <script.json>...');
})().catch(e => { console.log('ERR', e.message); revoke(); process.exit(1); });
