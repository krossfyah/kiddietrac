// Branded intro / outro cards for the training videos.
//
// Rendered in headless Chrome from the real KiddieTrac wordmark (assets/brand/login-wordmark.png,
// white lettering + teal rays and swoosh) and stepped frame by frame: every CSS animation is
// paused and its currentTime set for each frame, so the motion is smooth and identical on
// every run, whatever the machine is doing. Frames are encoded to a silent H.264 clip that
// tut.js joins to the screen recording.
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FPS = 25;
const LOGO = 'data:image/png;base64,' + fs.readFileSync(path.join(__dirname, 'assets', 'brand', 'login-wordmark.png')).toString('base64');
const AUDIENCE = {
  parent: 'For parents', educator: 'For educators', home_visitor: 'For home visitors',
  director: 'For directors and administrators', shared: 'For everyone',
};

const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/* The wordmark in three animated layers, cut from the one image by horizontal bands:
   rays (top 22%), lettering (middle), swoosh (bottom 22%). */
function logoHtml(width, delay) {
  const img = `background:url(${LOGO}) center/contain no-repeat;`;
  return `
  <div class="logo" style="width:${width}px;height:${Math.round(width * 275 / 701)}px;">
    <div class="rays"   style="${img}animation-delay:${delay + 0.15}s"></div>
    <div class="word"   style="${img}animation-delay:${delay}s"></div>
    <div class="swoosh" style="${img}animation-delay:${delay + 0.45}s"></div>
  </div>`;
}

const BASE_CSS = `
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{width:100%;height:100%;overflow:hidden}
  body{font-family:'Nunito','Segoe UI',Arial,sans-serif;color:#fff;
       background:radial-gradient(120% 90% at 20% 15%,#1F6080 0%,#0F2E52 45%,#0A1C3A 100%);}
  .glow{position:absolute;border-radius:50%;filter:blur(60px);opacity:.35;animation:drift 6s ease-in-out both}
  .stage{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
  .logo{position:relative}
  .logo>div{position:absolute;inset:0;animation-duration:.9s;animation-fill-mode:both;animation-timing-function:cubic-bezier(.2,.9,.25,1.15)}
  .rays{clip-path:inset(0 0 78% 0);transform-origin:44% 10%;animation-name:rays}
  .word{clip-path:inset(20% 0 21% 0);animation-name:rise}
  .swoosh{clip-path:inset(79% 100% 0 0);animation-name:wipe;animation-timing-function:cubic-bezier(.6,0,.2,1)!important}
  @keyframes rays{0%{opacity:0;transform:scale(.3) rotate(-25deg)}60%{opacity:1}100%{opacity:1;transform:none}}
  @keyframes rise{0%{opacity:0;transform:translateY(28px) scale(.96)}100%{opacity:1;transform:none}}
  @keyframes wipe{0%{clip-path:inset(79% 100% 0 0)}100%{clip-path:inset(79% 0 0 0)}}
  @keyframes fadeup{0%{opacity:0;transform:translateY(18px)}100%{opacity:1;transform:none}}
  @keyframes drift{0%{transform:translate(0,0) scale(.9)}100%{transform:translate(40px,-30px) scale(1.1)}}
  @keyframes black-in{0%{opacity:1}100%{opacity:0}}
  @keyframes black-out{0%{opacity:0}100%{opacity:1}}
  .fx{position:absolute;inset:0;background:#000;pointer-events:none}
`;

function introHtml(W, H, title, audience, dur) {
  const phone = H > W;
  const logoW = Math.round(W * (phone ? 0.62 : 0.36));
  const tSize = Math.round(phone ? W * 0.075 : H * 0.07);
  return `<!doctype html><html><head><meta charset="utf-8">
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;800;900&display=swap" rel="stylesheet">
  <style>${BASE_CSS}
    .chip{margin-top:${Math.round(H * (phone ? 0.05 : 0.055))}px;display:inline-block;padding:7px 16px;border-radius:999px;
          background:rgba(21,159,180,.22);border:1px solid rgba(94,214,226,.55);color:#9BE7EF;
          font-weight:800;letter-spacing:.14em;font-size:${Math.round(tSize * 0.36)}px;text-transform:uppercase;animation:fadeup .7s .9s both}
    .title{margin:${Math.round(H * 0.022)}px ${Math.round(W * 0.08)}px 0;font-weight:900;font-size:${tSize}px;line-height:1.15;animation:fadeup .8s 1.1s both}
    .aud{margin-top:${Math.round(H * 0.018)}px;font-weight:700;color:#5ED6E2;font-size:${Math.round(tSize * 0.5)}px;animation:fadeup .7s 1.35s both}
    .foot{position:absolute;bottom:${Math.round(H * 0.045)}px;left:0;right:0;text-align:center;opacity:.65;font-weight:700;
          font-size:${Math.round(tSize * 0.32)}px;letter-spacing:.08em;animation:fadeup .7s 1.6s both}
  </style></head><body>
  <div class="glow" style="width:${W * 0.6}px;height:${W * 0.6}px;left:-${W * 0.15}px;top:-${W * 0.2}px;background:#159FB4"></div>
  <div class="glow" style="width:${W * 0.5}px;height:${W * 0.5}px;right:-${W * 0.12}px;bottom:-${W * 0.18}px;background:#2D7BA8"></div>
  <div class="stage">
    ${logoHtml(logoW, 0.25)}
    <div class="chip">KiddieTrac Training</div>
    <div class="title">${esc(title)}</div>
    <div class="aud">${esc(audience)}</div>
  </div>
  <div class="foot">kiddietrac.com</div>
  <div class="fx" style="animation:black-in .6s 0s both"></div>
  <div class="fx" style="animation:black-out .5s ${dur - 0.5}s both"></div>
  </body></html>`;
}

function outroHtml(W, H, dur) {
  const phone = H > W;
  const logoW = Math.round(W * (phone ? 0.7 : 0.42));
  const s = Math.round(phone ? W * 0.05 : H * 0.036);
  return `<!doctype html><html><head><meta charset="utf-8">
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;800;900&display=swap" rel="stylesheet">
  <style>${BASE_CSS}
    .thanks{margin-top:${Math.round(H * 0.05)}px;font-weight:900;font-size:${Math.round(s * 1.35)}px;animation:fadeup .7s 1.3s both}
    .more{margin-top:${Math.round(H * 0.014)}px;font-weight:700;color:#9BE7EF;font-size:${s}px;animation:fadeup .7s 1.55s both}
    .pulse{animation:pulse 2.4s 1.6s ease-in-out both}
    @keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.035)}}
  </style></head><body>
  <div class="glow" style="width:${W * 0.6}px;height:${W * 0.6}px;right:-${W * 0.15}px;top:-${W * 0.2}px;background:#159FB4"></div>
  <div class="glow" style="width:${W * 0.5}px;height:${W * 0.5}px;left:-${W * 0.12}px;bottom:-${W * 0.18}px;background:#2D7BA8"></div>
  <div class="stage">
    <div class="pulse">${logoHtml(logoW, 0.35)}</div>
    <div class="thanks">Thanks for watching</div>
    <div class="more">More guides in Help &amp; guides</div>
  </div>
  <div class="fx" style="animation:black-in .6s 0s both"></div>
  <div class="fx" style="animation:black-out .7s ${dur - 0.7}s both"></div>
  </body></html>`;
}

/** Render html to a silent mp4 of `dur` seconds at W x H. */
async function renderHtml(browser, html, W, H, dur, outFile) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ktcard-'));
  const page = await browser.newPage();
  try {
    await page.setViewport({ width: W, height: H, deviceScaleFactor: 1 });
    await page.setContent(html, { waitUntil: 'networkidle0', timeout: 30000 }).catch(() => {});
    await page.evaluate(() => document.fonts && document.fonts.ready);
    await page.evaluate(() => document.getAnimations().forEach((a) => a.pause()));
    const n = Math.round(dur * FPS);
    for (let i = 0; i < n; i++) {
      const ms = (i / FPS) * 1000;
      await page.evaluate((ms) => document.getAnimations().forEach((a) => { a.currentTime = ms; }), ms);
      await page.screenshot({ path: path.join(dir, `f${String(i).padStart(4, '0')}.jpg`), type: 'jpeg', quality: 92 });
    }
    execFileSync(require('./ffpath'), ['-y', '-loglevel', 'error', '-framerate', String(FPS), '-i', path.join(dir, 'f%04d.jpg'),
      '-c:v', 'libx264', '-crf', '20', '-preset', 'medium', '-pix_fmt', 'yuv420p', outFile], { stdio: 'inherit' });
  } finally {
    await page.close();
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

async function intro(browser, { title, audience }, W, H, dur, outFile) {
  await renderHtml(browser, introHtml(W, H, title, AUDIENCE[audience] || '', dur), W, H, dur, outFile);
}

/** One outro per size and length; cached, because it is the same for every video. */
async function outro(browser, W, H, dur, cacheDir) {
  const f = path.join(cacheDir, `outro-${W}x${H}-${dur.toFixed(2)}.mp4`);
  if (!fs.existsSync(f)) await renderHtml(browser, outroHtml(W, H, dur), W, H, dur, f);
  return f;
}

module.exports = { intro, outro, FPS, AUDIENCE };
