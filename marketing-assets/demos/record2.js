// Product clips paced to their narration: each action starts at its sentence's cue time.
const H = require('./harness');
const fs = require('fs');
const path = require('path');
const FF = path.resolve(__dirname, '../vid/node_modules/@ffmpeg-installer/win32-x64/ffmpeg.exe');
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const CUES = JSON.parse(fs.readFileSync(path.join(__dirname, 'audio', 'cues.json'), 'utf8'));

async function smooth(page, px, ms) {
  await page.evaluate(async (px, ms) => {
    const c = [document.getElementById('appMain'), document.scrollingElement].filter(Boolean);
    const el = c.find(e => e.scrollHeight > e.clientHeight + 20) || document.scrollingElement;
    const from = el.scrollTop, t0 = performance.now();
    await new Promise(res => { (function step(now) { const k = Math.min(1, (now - t0) / ms); const e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2; el.scrollTop = from + px * e; if (k < 1) requestAnimationFrame(step); else res(); })(t0); });
  }, px, ms);
}

// Each scene: list of [cueIndex, action]; the action starts when that sentence starts.
const SCENES = {
  day: [
    [1, (p) => smooth(p, 160, 2500)],
    [2, (p) => smooth(p, 620, 3200)],
  ],
  ratios: [
    [1, (p) => smooth(p, 420, 3200)],
    [2, (p) => smooth(p, 140, 2000)],
  ],
  parent: [
    [1, (p) => smooth(p, 300, 2600)],
    [2, async (p) => { await p.evaluate(() => { location.hash = 'today'; }); await sleep(1800); await smooth(p, 460, 3000); }],
    [3, (p) => p.evaluate(() => { location.hash = 'messages'; })],
    [4, async (p) => { await p.evaluate(() => { location.hash = 'billing'; }); await sleep(1600); await smooth(p, 300, 2400); }],
  ],
};
const START = { day: 'provider-day', ratios: 'room-ratios', parent: 'home' };

(async () => {
  const which = process.argv.slice(2);
  const out = path.join(__dirname, 'video'); fs.mkdirSync(out, { recursive: true });
  const b = await H.browser();
  for (const name of which) {
    const mobile = name === 'parent';
    const page = await H.newPage(b, mobile);
    await H.login(page, mobile ? 'parent' : 'admin', mobile ? null : 'agency_admin');
    await H.go(page, mobile ? 'today' : 'dashboard', 4000);          // warm caches
    await H.go(page, START[name], 6000);                               // the screen, fully painted
    const cues = CUES[name], total = CUES[name + '_total'];
    const webm = path.join(out, name + '-vo.webm');
    const rec = await page.screencast({ path: webm, ffmpegPath: FF });
    const t0 = Date.now();
    for (const [i, act] of SCENES[name]) {
      const wait = cues[i].start * 1000 - (Date.now() - t0);
      if (wait > 0) await sleep(wait);
      await act(page);
    }
    const rest = total * 1000 + 600 - (Date.now() - t0);                // record past the end; trimmed to the audio
    if (rest > 0) await sleep(rest);
    await rec.stop();
    await page.close();
    console.log('recorded', name, ((Date.now() - t0) / 1000).toFixed(1) + 's');
  }
  await b.close();
})().catch(e => { console.log('ERR', e.message); process.exit(1); });
