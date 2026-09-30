// Short silent product clips from the real portal (demo data, scrubbed), browsing only: no saves.
const H = require('./harness');
const fs = require('fs');
const path = require('path');
const FF = path.resolve(__dirname, '../vid/node_modules/@ffmpeg-installer/win32-x64/ffmpeg.exe');
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function smooth(page, px, ms) {
  await page.evaluate(async (px, ms) => {
    const cands = [document.getElementById('appMain'), document.scrollingElement].filter(Boolean);
    const el = cands.find(e => e.scrollHeight > e.clientHeight + 20) || document.scrollingElement;
    const from = el.scrollTop, t0 = performance.now();
    await new Promise(res => { (function step(now) { const k = Math.min(1, (now - t0) / ms); const e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2; el.scrollTop = from + px * e; if (k < 1) requestAnimationFrame(step); else res(); })(t0); });
  }, px, ms);
}
async function clickText(page, re) {
  return page.evaluate((src, flags) => {
    const r = new RegExp(src, flags);
    const el = [...document.querySelectorAll('a, button, [role=button], .kt-tile, nav *')].find(e => e.offsetParent && r.test((e.textContent || '').trim()));
    if (el) { el.click(); return true; } return false;
  }, re.source, re.flags);
}

const SCENES = {
  async parent(page) {
    // In-app navigation by address (what a tap does), so every step lands.
    const nav = async (h) => { await page.evaluate((h) => { location.hash = h; }, h); await sleep(2600); };
    await sleep(1200); await smooth(page, 300, 1800); await sleep(700); await smooth(page, -300, 1200);
    await nav('today'); await smooth(page, 520, 2600); await sleep(1000);
    await nav('messages'); await sleep(800);
    await nav('billing'); await smooth(page, 360, 2000); await sleep(1200);
  },
  async ratios(page) {
    await sleep(1000); await smooth(page, 520, 3200); await sleep(1100); await smooth(page, -520, 2600); await sleep(900);
  },
  async day(page) {

    await sleep(1000); await smooth(page, 460, 3000); await sleep(1400); await smooth(page, -460, 2400); await sleep(900);
  },
};

(async () => {
  const which = process.argv.slice(2);
  const out = path.join(__dirname, 'video'); fs.mkdirSync(out, { recursive: true });
  const b = await H.browser();
  for (const name of which) {
    const mobile = name === 'parent';
    const page = await H.newPage(b, mobile);
    await H.login(page, mobile ? 'parent' : 'admin', mobile ? null : 'agency_admin');
    // Warm up (and let the scrubber settle) before recording starts.
    await H.go(page, mobile ? 'home' : 'dashboard', 4000);
    if (!mobile) await H.go(page, name === 'ratios' ? 'room-ratios' : 'provider-day', 5500);
    const webm = path.join(out, name + '.webm');
    const rec = await page.screencast({ path: webm, ffmpegPath: FF });
    await SCENES[name](page);
    await rec.stop();
    await page.close();
    console.log('recorded', name, fs.statSync(webm).size, 'bytes');
  }
  await b.close();
})().catch(e => { console.log('ERR', e.message); process.exit(1); });
