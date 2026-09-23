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
/* puppeteer-core against the Chrome already on this machine. The HOST has no node at
   all, so capture has always run locally and the PNGs are uploaded; pulling a second
   ~150MB Chromium to do it would buy nothing. */
const puppeteer = require('puppeteer-core');
const CHROME = process.env.CHROME_PATH
  || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const cleanupInPage = require('./clean');

const TOKEN = process.env.KT_TOKEN;
if (!TOKEN) { console.error('Set KT_TOKEN'); process.exit(1); }

const APP = 'https://app.kiddietrac.com/dashboard.html';
const API = 'https://api.kiddietrac.com/api/v1';
const AGENCY = '6';                       // Test Agency — never a live customer
const OUT = path.join(__dirname, 'out');

// Each shot: the hash to open, the file to write, and how long its data needs.
const SHOTS = [
  // The screens rebuilt in September 2026. Test Agency only — see the header.
  { file: 'account-ledger.png',      hash: 'account-ledgers',  wait: 9000 },
  { file: 'payroll.png',             hash: 'payroll',          wait: 9000 },
  { file: 'contacts.png',            hash: 'contacts',         wait: 7000 },
  { file: 'refunds.png',             hash: 'refunds',          wait: 8000 },
  { file: 'payment-schedules.png',   hash: 'payment-plans',    wait: 7000 },
  { file: 'invoices-scheduled.png',  hash: 'external-billing', wait: 8000 },
  { file: 'audit-log.png',           hash: 'audit-logs',       wait: 8000 },
];

// Chrome shown to a customer should not display the platform-admin furniture: the
// PLATFORM nav group, the "View as" pill, the diagnostics chip. They are Anthony's, not
// the reader's, and they make a guide look like it was written for somebody else.
// Page cleanup lives in clean.js — walked in the DOM, not guessed at with CSS.


(async () => {
  fs.mkdirSync(path.join(OUT, 'tour'), { recursive: true });

  const browser = await puppeteer.launch({
    executablePath: CHROME,
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
