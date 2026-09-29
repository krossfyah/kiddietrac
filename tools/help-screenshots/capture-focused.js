/* Help screenshots for the 2026-09-29 sweep. TEST AGENCY (6) ONLY — refuses otherwise.
   Each shot: open a hash, run steps (click by visible text), then either crop to the
   card containing `crop` text, or take the viewport; ring the control named by `ring`. */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');
const cleanupInPage = require('./clean');

const TOKEN = process.env.KT_TOKEN;
if (!TOKEN) { console.error('Set KT_TOKEN'); process.exit(1); }
const APP = 'https://app.kiddietrac.com/dashboard.html';
const API = 'https://api.kiddietrac.com/api/v1';
const AGENCY = '6';
const OUT = path.join(__dirname, 'out');
const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null;

const SHOTS = [
  { file: 'carrier-kinds-of-texts.png', hash: 'sms-settings', wait: 8000,
    h: 470, crop: 'Kinds of texts this agency sends', ring: 'Kinds of texts this agency sends', label: 'Tick what your agency sends' },
  { file: 'carrier-voice-reasons.png', hash: 'sms-settings', wait: 8000, steps: ['Voice calls'],
    h: 420, crop: 'Reasons that may place calls', ring: 'Reasons that may place calls', label: 'Reasons calls may be placed for' },
  { file: 'carrier-voice-test.png', hash: 'sms-settings', wait: 8000, steps: ['Voice calls'],
    h: 470, crop: 'Test message', ring: 'Call my own number', label: 'Rings only you' },
  { file: 'sms-broadcast-voice.png', hash: 'sms', wait: 8000, steps: ['Voice call'],
    h: 700, crop: 'Send by', ring: 'Voice call', label: 'Send by: text or call' },
  { file: 'my-texts-and-calls.png', hash: 'settings', wait: 8000, steps: ['Profile'],
    h: 700, crop: 'Phone calls', ring: "Don't phone me at all", label: 'Overrides every call' },
  { file: 'passkeys-security.png', hash: 'settings', wait: 8000, steps: ['Security'],
    crop: 'Add a passkey', ring: 'Add a passkey', label: 'Face, fingerprint or PIN' },
  { file: 'email-template-staff-welcome.png', hash: 'email-templates', wait: 8000,
    select: 'staff-welcome', crop: 'Template to edit', ring: 'Template to edit', label: 'Pick the email to edit' },
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await puppeteer.launch({
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
  const me = await (await fetch(API + '/auth/me', {
    headers: { Authorization: 'Bearer ' + TOKEN, Accept: 'application/json' } })).json();
  const user = me.user || me;

  await page.goto(APP, { waitUntil: 'domcontentloaded' });
  await page.evaluate((tok, u, agency) => {
    sessionStorage.setItem('kt_token', tok); localStorage.setItem('kt_token', tok);
    sessionStorage.setItem('kt_user', JSON.stringify(u));
    sessionStorage.setItem('kt_active_agency_id', agency);
    try { localStorage.setItem('kt_tour_done', '1'); localStorage.setItem('kt_welcome_seen', '1'); } catch (e) {}
  }, TOKEN, user, AGENCY);

  for (const shot of SHOTS) {
    if (ONLY && !ONLY.includes(shot.file)) continue;
    await page.goto(APP + '#' + shot.hash, { waitUntil: 'domcontentloaded' });
    await page.evaluate((h) => { location.hash = '#' + h; }, shot.hash);
    await sleep(shot.wait);

    const agency = await page.evaluate(() => sessionStorage.getItem('kt_active_agency_id'));
    if (agency !== AGENCY) { console.error('REFUSED ' + shot.file + ': agency ' + agency); continue; }

    // Dismiss the first-run tour / welcome if it shows.
    await page.evaluate(() => {
      document.querySelectorAll('button, a').forEach((b) => {
        const t = (b.textContent || '').trim();
        if (/^(Skip|Skip tour|Maybe later|Not now|Close tour)$/i.test(t) && b.offsetParent) b.click();
      });
    });
    await sleep(600);

    for (const txt of shot.steps || []) {
      const ok = await page.evaluate((t) => {
        const els = [...document.querySelectorAll('#appMain button, #appMain [role=tab], #appMain a, #appMain label')]
          .filter((e) => e.offsetParent && (e.textContent || '').replace(/\s+/g, ' ').trim().includes(t));
        els.sort((a, b) => a.textContent.length - b.textContent.length);
        if (els[0]) { els[0].click(); return true; } return false;
      }, txt);
      if (!ok) console.log('   step not found: ' + txt);
      await sleep(2500);
    }
    if (shot.select) {
      await page.evaluate((v) => {
        const s = [...document.querySelectorAll('#appMain select')].find((x) => [...x.options].some((o) => o.value === v));
        if (s) { s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }
      }, shot.select);
      await sleep(4000);
    }

    if (process.env.DEBUG) console.log(await page.evaluate(() => [...document.querySelectorAll('#appMain *')]
      .filter((e) => e.children.length === 0 && /passkey/i.test(e.textContent))
      .map((e) => { const r = e.getBoundingClientRect(); return e.tagName + ' vis=' + !!e.offsetParent + ' y=' + Math.round(r.top) + ' w=' + Math.round(r.width) + ' | ' + e.textContent.trim().slice(0, 80); }).join(' || ')));
    await page.evaluate(cleanupInPage);
    await sleep(400);

    const box = await page.evaluate((shot) => {
      const byText = (t) => {
        const all = [...document.querySelectorAll('#appMain *')].filter((e) => e.offsetParent !== null
          && e.children.length < 4 && (e.textContent || '').replace(/\s+/g, ' ').trim().includes(t));
        const norm = (e) => (e.textContent || '').replace(/\s+/g, ' ').trim();
        all.sort((a, b) => (norm(a) === t ? 0 : 1) - (norm(b) === t ? 0 : 1) || a.textContent.length - b.textContent.length);
        return all[0] || null;
      };
      const scrollTo = (el, offset) => {
        let sc = el.parentElement;
        while (sc && sc !== document.body) {
          const cs = getComputedStyle(sc);
          if (/(auto|scroll)/.test(cs.overflowY) && sc.scrollHeight > sc.clientHeight + 4) break;
          sc = sc.parentElement;
        }
        const delta = el.getBoundingClientRect().top - offset;
        document.documentElement.style.scrollBehavior = 'auto';
        if (sc && sc !== document.body) { sc.style.scrollBehavior = 'auto'; sc.scrollTo({ top: sc.scrollTop + delta, behavior: 'instant' }); }
        else window.scrollTo({ top: window.scrollY + delta, behavior: 'instant' });
      };
      const mainClip = () => {
        const m = document.getElementById('appMain'); const tb = document.getElementById('kt-topbar');
        const mr = m.getBoundingClientRect(); const y = tb ? Math.max(tb.getBoundingClientRect().bottom + 4, 0) : 0;
        return { x: Math.max(mr.left, 0), y, width: Math.min(mr.width, innerWidth - Math.max(mr.left, 0)), height: innerHeight - y };
      };
      document.querySelectorAll('.kt-shot-ring').forEach((n) => n.remove());
      const tight = (el) => {
        const er = el.getBoundingClientRect();
        if (!/^(BUTTON|INPUT|SELECT|TEXTAREA|A)$/.test(el.tagName)) {
          const rg = document.createRange(); rg.selectNodeContents(el); const r = rg.getBoundingClientRect();
          if (r.width > 0 && r.width < er.width - 20) return r;
        }
        return er;
      };
      const anchor = shot.crop && byText(shot.crop);
      const ringEl = shot.ring && byText(shot.ring);
      let clip = null;
      if (anchor) {
        let c = anchor;
        while (c.parentElement && c.parentElement.id !== 'appMain') {
          const r = c.getBoundingClientRect(); const cs = getComputedStyle(c);
          if (r.width >= 420 && r.height >= 120 && (cs.borderTopWidth !== '0px' || cs.boxShadow !== 'none')) break;
          c = c.parentElement;
        }
        scrollTo(anchor, 130);
        const ar = anchor.getBoundingClientRect(); const cr = c.getBoundingClientRect();
        const y = Math.max(ar.top - 24, 0);
        const h = Math.min(shot.h || 560, innerHeight - y, cr.bottom + 12 - y);
        if (h > 120) clip = { x: Math.max(cr.left - 12, 0), y, width: Math.min(cr.width + 24, innerWidth - Math.max(cr.left - 12, 0)), height: h };
      } else if (ringEl) { scrollTo(ringEl, innerHeight / 2 - 60); }
      if (!clip) clip = mainClip();
      if (ringEl) {
        const r = tight(ringEl);
        const ring = document.createElement('div'); ring.className = 'kt-shot-ring';
        ring.style.cssText = 'position:fixed;z-index:2147483647;pointer-events:none;border:3px solid #E11D48;'
          + 'border-radius:10px;box-shadow:0 0 0 4px rgba(225,29,72,.18);left:' + (r.left - 8) + 'px;top:' + (r.top - 6)
          + 'px;width:' + (r.width + 16) + 'px;height:' + (r.height + 12) + 'px;';
        document.body.appendChild(ring);
        if (shot.label) {
          const tag = document.createElement('div'); tag.className = 'kt-shot-ring'; tag.textContent = shot.label;
          tag.style.cssText = 'position:fixed;z-index:2147483647;pointer-events:none;background:#E11D48;color:#fff;'
            + 'font:800 13px system-ui,sans-serif;padding:5px 11px;border-radius:999px;white-space:nowrap;';
          document.body.appendChild(tag);
          const right = clip ? clip.x + clip.width - 10 : innerWidth - 10;
          const tw = tag.getBoundingClientRect().width;
          let left = r.right + 18, top = r.top + r.height / 2 - 13;
          if (left + tw > right) { if (r.left - tw - 18 > (clip ? clip.x + 10 : 10)) { left = r.left - tw - 18; } else { left = Math.max(r.left, right - tw); top = r.bottom + 12; } }
          tag.style.left = left + 'px'; tag.style.top = top + 'px';
        }
      }
      return clip;
    }, shot);
    await sleep(500);

    const dest = path.join(OUT, shot.file);
    const full = await page.screenshot({ encoding: 'base64', captureBeyondViewport: false });
    const b64 = await page.evaluate(async (src, box) => {
      const img = new Image(); img.src = 'data:image/png;base64,' + src; await img.decode();
      const k = img.naturalWidth / innerWidth;
      const c = document.createElement('canvas');
      c.width = Math.round(box.width * k); c.height = Math.round(box.height * k);
      c.getContext('2d').drawImage(img, box.x * k, box.y * k, box.width * k, box.height * k, 0, 0, c.width, c.height);
      return c.toDataURL('image/png').split(',')[1];
    }, full, box);
    fs.writeFileSync(dest, Buffer.from(b64, 'base64'));
    console.log('  ' + shot.file.padEnd(36) + String(fs.statSync(dest).size).padStart(9) + (box ? '  crop' : '  viewport'));
  }
  await browser.close();
  console.log('done');
})().catch((e) => { console.error(e); process.exit(1); });
