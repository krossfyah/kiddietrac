// Capture real KiddieTrac screens for the marketing demos, with every real person scrubbed.
// Separate headless Chrome profile: the user's own browser and storage are never touched.
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const os = require('os');
const path = require('path');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const API = 'https://api.kiddietrac.com/api/v1';
const APP = 'https://app.kiddietrac.com';
const TOK = JSON.parse(fs.readFileSync(path.join(__dirname, 'tokens.json'), 'utf8'));

// Runs in the page before any page script, and keeps running: real people out, demo names in.
function scrubInPage() {
  const NAMES = [
    [/\bAnthony Hosein\b/g, 'Rebecca Bright'], [/\bAnthony Home Visitor\b/g, 'Dana Home Visitor'],
    [/\bAnthony\b/g, 'Rebecca'], [/\bHosein\b/g, 'Morgan'],
    [/\bSafia Ali\b/g, 'Nadia Karim'], [/\bSafia\b/g, 'Nadia'],
    [/\bLloydene King\b/g, 'Joan Porter'], [/\bLloydene\b/g, 'Joan'],
    [/\biLearn Home Childcare Inc\.?/g, 'Sunny Meadows Childcare'], [/\biLearn\b/g, 'Sunny Meadows'],
    [/\bTest Agency\b/g, 'Maple Leaf Child Care'],
  ];
  // Children of the families that involve real people: their cards/rows are removed, not renamed.
  const REAL_KIDS = /\b(Hosein|Aria|Alyssa|Ali Family)\b/;
  const FACES = /api\.kiddietrac\.com|\/storage\/|blob:|pravatar\.cc|randomuser\.me|uifaces|generated\.photos|thispersondoesnotexist/i;
  const EMAIL = /[A-Za-z0-9._%+-]+@(?!demo\.testagency\.com)[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g;
  // A phone number stands on its own: not glued to a letter, digit or hyphen (invoice numbers are).
  const PHONE = /(?<![\w-])(\+?1[ .-]?)?(\(\d{3}\) ?|\d{3}[ .-])\d{3}[ .-]\d{4}(?![\w-])/g;
  function clean(s) {
    if (!s || !/[A-Za-z0-9]/.test(s)) return s;
    let t = s;
    for (const [re, to] of NAMES) t = t.replace(re, to);
    return t.replace(EMAIL, 'office@demo.testagency.com').replace(PHONE, '(416) 555-0142');
  }
  // The whole list item: the ancestor that is one of several siblings in a grid/list, or a table row.
  function hideRow(node) {
    const start = node.parentElement;
    if (!start || /^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE|TITLE|HEAD)$/.test(start.tagName)) return;
    const big = (e) => !e || e === document.body || e === document.documentElement || /^(app|appMain|appSidebar|appShell)$/.test(e.id) ||
      (e.querySelector && e.querySelector('#appMain, #appSidebar, #navLinks')) || e.getBoundingClientRect().height > 360;
    // A card-like ancestor first: hiding only the name leaves the family's balance on show.
    for (let c = start, i = 0; i < 8 && c && !big(c); i++, c = c.parentElement) {
      if (/card|tile/i.test(String(c.className || '')) && c.getBoundingClientRect().height >= 40) { c.style.setProperty('display', 'none', 'important'); return; }
    }
    let el = start, pick = null;
    for (let i = 0; i < 8 && el && !big(el); i++, el = el.parentElement) {
      if (el.tagName === 'TR' || el.tagName === 'LI') { pick = el; break; }
      const p = el.parentElement;
      if (p && p.children.length >= 3 && el.getBoundingClientRect().height >= 40) { pick = el; break; }
    }
    (pick || start).style.setProperty('display', 'none', 'important');
  }
  const STOCK = ['wooden-blocks', 'painting-hands', 'reading-together', 'sensory-play', 'playground', 'crayon-hands', 'toy-play', 'block-hands', 'rainbow-art', 'markers-drawing'];
  let n = 0;
  function img(el) {
    const src = el.getAttribute('src') || '';
    if (el.dataset.ktScrubbed || !src) return;
    // A small image from anywhere but KiddieTrac is a profile photo: it could be a real face.
    const ext = /^https?:\/\//i.test(src) && !/^https?:\/\/(www|app)\.kiddietrac\.com\//i.test(src);
    const sz = Math.max(el.width || 0, el.height || 0);
    if (ext && sz && sz < 72) { el.dataset.ktScrubbed = '1'; el.style.visibility = 'hidden'; return; }
    // Anything uploaded (photos, avatars, logos from the API) could show a real person.
    if (/api\.kiddietrac\.com|\/storage\/|\/media\/|blob:|amazonaws|signed/i.test(src) || /avatar|photo/i.test(String(el.className || ''))) {
      el.dataset.ktScrubbed = '1';
      const w = el.width || el.naturalWidth || 0;
      if (w && w < 72) { el.style.visibility = 'hidden'; return; }
      el.src = 'https://www.kiddietrac.com/images/life/' + STOCK[n++ % STOCK.length] + '-800.jpg';
      el.style.objectFit = 'cover';
    }
  }
  function walk(root) {
    if (!root) return;
    const tw = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let x;
    while ((x = tw.nextNode())) {
      if (REAL_KIDS.test(x.nodeValue)) hideRow(x);
      const v = clean(x.nodeValue); if (v !== x.nodeValue) x.nodeValue = v;
    }
    if (!root.querySelectorAll) return;
    root.querySelectorAll('input,textarea').forEach(i => { const v = clean(i.value); if (v !== i.value) i.value = v; });
    root.querySelectorAll('[title],[placeholder],[aria-label]').forEach(e => ['title', 'placeholder', 'aria-label'].forEach(a => { const v = e.getAttribute(a); if (v) { const c = clean(v); if (c !== v) e.setAttribute(a, c); } }));
    root.querySelectorAll('img').forEach(img);
    // Uploaded photos, and portrait services that serve photos of real people (pravatar, randomuser...).
    root.querySelectorAll('[style*="background"]').forEach(e => { const b = e.style.backgroundImage || ''; if (FACES.test(b)) e.style.backgroundImage = 'none'; });
    root.querySelectorAll('img').forEach(i => { if (FACES.test(i.getAttribute('src') || '')) i.style.setProperty('visibility', 'hidden', 'important'); });
    const t = clean(document.title); if (t !== document.title) document.title = t;
    chrome();
  }
  // Super-admin chrome: an agency sees none of this.
  function chrome() {
    document.querySelectorAll('.sidebar-section').forEach(sec => { const l = sec.querySelector('.sidebar-section-label'); if (l && /^(platform|sales|website|reseller)$/i.test((l.textContent || '').replace(/[^A-Za-z]/g, ''))) sec.style.setProperty('display', 'none', 'important'); });
    document.querySelectorAll('#appMain *').forEach(e => { const t = (e.textContent || '').trim(); if (t.length < 30 && /Agency email OFF$/i.test(t) && e.children.length <= 3) e.style.setProperty('display', 'none', 'important'); });
    // The sandbox notice on the demo agency's billing, and the install prompt.
    document.querySelectorAll('div, section, aside').forEach(e => {
      const t = (e.textContent || '').trim();
      if (t.length < 260 && (/^\W*TEST MODE\b/.test(t) || /^Add Kiddietrac to your home screen/i.test(t.replace(/^\W+/, '')))) e.style.setProperty('display', 'none', 'important');
    });
    document.querySelectorAll('#kt-agency-switcher span').forEach(sp => { if ((sp.textContent || '').trim() === 'PLAT') sp.style.setProperty('display', 'none', 'important'); });
  }
  const css = document.createElement('style');
  css.textContent = [
    '#kt-viewas, .kt-viewas, [data-kt-viewas], .kt-superadmin, .kt-role-badge, .kt-plat-chip, .kt-tb-role, #kt-view-as, #kt-imp-fab',
    // The demo parent has not signed onboarding; the app is rendered underneath the gate.
    '#kt-agree, #kt-agree.kt-scrim',
  ].join(',') + '{display:none!important}' +
    // Every uploaded image (staff and family photos) is hidden before it can paint, whatever its size.
    'img[src*="api.kiddietrac.com"],img[src^="blob:"],img[srcset*="api.kiddietrac.com"]{visibility:hidden!important}' + [
    '#kt-never-matches',
    '.kt-toast, #kt-toasts, [class*="toast"], [id*="toast"], .kt-update-banner, #kt-update-banner, .kt-welcome-tour, #kt-tour',
  ].join(',') + '{display:none!important}';
  function start() {
    (document.head || document.documentElement).appendChild(css);
    walk(document.documentElement);
    new MutationObserver(ms => ms.forEach(m => {
      m.addedNodes.forEach(nd => {
        if (nd.nodeType === 1) walk(nd);
        else if (nd.nodeType === 3) { if (REAL_KIDS.test(nd.nodeValue)) hideRow(nd); const v = clean(nd.nodeValue); if (v !== nd.nodeValue) nd.nodeValue = v; }
      });
      if (m.type === 'characterData') { const v = clean(m.target.nodeValue); if (v !== m.target.nodeValue) m.target.nodeValue = v; }
    })).observe(document.documentElement, { childList: true, subtree: true, characterData: true });
    setInterval(() => walk(document.documentElement), 500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
}
const SCRUB = '(' + scrubInPage.toString() + ')();';

async function browser() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ktcap-'));
  return puppeteer.launch({
    executablePath: CHROME, headless: true, pipe: true, userDataDir: dir, timeout: 30000, defaultViewport: null,
    args: ['--no-first-run', '--no-default-browser-check', '--disable-extensions', '--hide-scrollbars', '--lang=en-CA', '--window-size=1440,900'],
  });
}

async function login(page, who, primaryRole) {
  const tok = TOK[who];
  await page.goto(APP + '/index.html', { waitUntil: 'domcontentloaded' });
  const me = await page.evaluate(async (API, tok) => (await fetch(API + '/auth/me', { headers: { Authorization: 'Bearer ' + tok, Accept: 'application/json' } })).json(), API, tok);
  const u = me.user || me;
  // Present as a plain agency admin: a client-side copy only, the server's rules are unchanged.
  if (primaryRole) u.primary_role = primaryRole;
  await page.evaluate((tok, u) => {
    sessionStorage.setItem('kt_token', tok); sessionStorage.setItem('kt_user', JSON.stringify(u));
    sessionStorage.setItem('kt_active_agency_id', '6'); sessionStorage.setItem('kt_active_agency_name', 'Test Agency');
    localStorage.setItem('kt_tour_done', '1'); localStorage.setItem('kt_welcome_seen', '1');
  }, tok, u);
  return u;
}

async function dismiss(page) {
  await page.evaluate(() => {
    document.querySelectorAll('button, a').forEach(b => { const t = (b.textContent || '').trim(); if (/^(Skip( tour)?|Not now|Maybe later|Got it)$/i.test(t) && b.offsetParent) try { b.click(); } catch (e) {} });
  });
}

async function newPage(b, mobile) {
  const page = await b.newPage();
  await page.evaluateOnNewDocument(SCRUB);
  if (mobile) await page.emulate({ viewport: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true }, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36' });
  else {
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    // The page decides "phone" from the SCREEN size at parse time; headless reports a small one.
    const c = await page.target().createCDPSession();
    await c.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false, screenWidth: 1440, screenHeight: 900 });
  }
  return page;
}

async function go(page, hash, wait) {
  await page.goto(APP + '/dashboard.html#' + hash, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
  await new Promise(r => setTimeout(r, wait || 5000));
  await dismiss(page);
  await new Promise(r => setTimeout(r, 900));
}

module.exports = { browser, login, newPage, go, dismiss, APP, SCRUB };

if (require.main === module) {
  (async () => {
    const [who, role, mobile, ...hashes] = process.argv.slice(2);
    const b = await browser();
    const page = await newPage(b, mobile === 'm');
    await login(page, who, role === '-' ? null : role);
    fs.mkdirSync(path.join(__dirname, 'shots'), { recursive: true });
    for (const h of hashes) {
      await go(page, h, 6000);
      const f = path.join(__dirname, 'shots', (mobile === 'm' ? 'm-' : '') + h.replace(/[^a-z0-9-]/gi, '_') + '.png');
      await page.screenshot({ path: f });
      console.log('shot', path.basename(f));
    }
    await b.close();
  })().catch(e => { console.error(e); process.exit(1); });
}
