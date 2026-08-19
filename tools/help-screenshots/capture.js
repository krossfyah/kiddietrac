/* ═══════════════════════════════════════════════════════════════════
   KiddieTrac — help/tour screenshot capture, from TEST AGENCY only.

   The previous screenshots were taken from the live iLearn agency and published to a
   public, unauthenticated URL: real children's names and ages, real family names, and
   home-childcare providers' residential addresses. They have been removed. Everything
   here is captured from Test Agency (agency 6), whose data is seeded demo content.

   Auth is a minted personal access token written straight into sessionStorage, so no
   password is typed or stored anywhere. Mint one with:

     php artisan tinker --execute="echo User::find(1)->createToken('help-capture')->plainTextToken;"

   and pass it as KT_TOKEN. Delete it afterwards.

   Usage:  KT_TOKEN='...' node capture.js
   ═══════════════════════════════════════════════════════════════════ */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');
const cleanupInPage = require('./clean');

const TOKEN = process.env.KT_TOKEN;
if (!TOKEN) { console.error('Set KT_TOKEN'); process.exit(1); }

const APP = 'https://app.kiddietrac.com/dashboard.html';
const API = 'https://api.kiddietrac.com/api/v1';
const AGENCY = '6';                       // Test Agency — never a live customer
const OUT = path.join(__dirname, 'out');

// Each shot: the hash to open, the file to write, and how long its data needs.
const SHOTS = [
  { file: 'tour/overview.png',   hash: 'dashboard',           wait: 6000 },
  { file: 'provider-map.png',    hash: 'provider-map',        wait: 9000 },
  { file: 'tour/children.png',   hash: 'admin-children',      wait: 6500 },
  { file: 'tour/families.png',   hash: 'admin-families',      wait: 6500 },
  { file: 'tour/daily-log.png',  hash: 'care-log',            wait: 6500 },
  { file: 'campaigns.png',       hash: 'marketing-campaigns', wait: 6000 },
  { file: 'tour/billing.png',    hash: 'billing-settings',    wait: 6000 },
  { file: 'tour/help.png',       hash: 'help',                wait: 6500 },
  { file: 'tour/branding.png',   hash: 'admin-branding',      wait: 6000 },
  { file: 'edit-agency.png',     hash: 'dashboard',           wait: 6000 },
  { file: 'calendar.png',        hash: 'staff-calendar',      wait: 7000 },
  { file: 'menu.png',            hash: 'menu',                wait: 7000 },
  { file: 'room-assignments.png',hash: 'educator-rooms',      wait: 6000 },
  { file: 'tickets.png',         hash: 'tickets',             wait: 6000 },
];

// Chrome shown to a customer should not display the platform-admin furniture: the
// PLATFORM nav group, the "View as" pill, the diagnostics chip. They are Anthony's, not
// the reader's, and they make a guide look like it was written for somebody else.
// Page cleanup lives in clean.js — walked in the DOM, not guessed at with CSS.


(async () => {
  fs.mkdirSync(path.join(OUT, 'tour'), { recursive: true });

  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--force-device-scale-factor=2'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });

  // Identity, fetched with the token so no password is involved and nothing personal is
  // hard-coded into this script.
  const me = await (await fetch(API + '/auth/me', {
    headers: { Authorization: 'Bearer ' + TOKEN, Accept: 'application/json' },
  })).json();

  await page.goto(APP, { waitUntil: 'domcontentloaded' });
  await page.evaluate((tok, user, agency) => {
    sessionStorage.setItem('kt_token', tok);
    localStorage.setItem('kt_token', tok);
    sessionStorage.setItem('kt_user', JSON.stringify(user));
    sessionStorage.setItem('kt_active_agency_id', agency);
  }, TOKEN, me, AGENCY);

  for (const shot of SHOTS) {
    const target = APP + '#' + shot.hash;
    await page.goto(target, { waitUntil: 'domcontentloaded' });
    // The shell routes on hashchange, so a same-document navigation needs a nudge.
    await page.evaluate((h) => { window.location.hash = '#' + h; }, shot.hash);
    await new Promise((r) => setTimeout(r, shot.wait));
    await page.evaluate(cleanupInPage);
    await new Promise((r) => setTimeout(r, 500));

    const dest = path.join(OUT, shot.file);
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    await page.screenshot({ path: dest, type: 'png' });

    const title = await page.evaluate(() => (document.title || '') + ' | ' +
      (document.querySelector('#appMain') || {}).textContent?.replace(/\s+/g, ' ').trim().slice(0, 60));
    console.log(`  ${shot.file.padEnd(24)} ${fs.statSync(dest).size.toString().padStart(8)} bytes  ${title}`);
  }

  await browser.close();
  console.log('done');
})().catch((e) => { console.error(e); process.exit(1); });
