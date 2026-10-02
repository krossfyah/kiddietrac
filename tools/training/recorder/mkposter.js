const puppeteer = require('../demo/node_modules/puppeteer-core');
const fs = require('fs'), os = require('os'), path = require('path');
(async () => {
  const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, pipe: true, userDataDir: fs.mkdtempSync(path.join(os.tmpdir(), 'ktpos-')) });
  const p = await b.newPage(); await p.setViewport({ width: 640, height: 480, deviceScaleFactor: 1 });
  let qr = ''; for (let y = 0; y < 21; y++) for (let x = 0; x < 21; x++) { const f = (x < 7 && y < 7) || (x > 13 && y < 7) || (x < 7 && y > 13); const ring = f && ((x % 14 === 0 || x % 14 === 6 || y % 14 === 0 || y % 14 === 6) || (x % 14 >= 2 && x % 14 <= 4 && y % 14 >= 2 && y % 14 <= 4)); const on = f ? ring : ((x * 7 + y * 13 + x * y) % 3 === 0); if (on) qr += `<rect x="${x}" y="${y}" width="1" height="1"/>`; }
  await p.setContent(`<html><body style="margin:0;width:640px;height:480px;background:linear-gradient(180deg,#d9cbb5,#c8b89e);display:flex;align-items:center;justify-content:center;font-family:Arial">
   <div style="width:250px;background:#fff;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.35);padding:16px;text-align:center;transform:rotate(-2deg);">
    <div style="font-weight:800;color:#0E7C90;font-size:18px;">Check in here</div>
    <svg viewBox="0 0 21 21" width="170" height="170" style="margin:10px auto;display:block;shape-rendering:crispEdges">${qr}</svg>
    <div style="font-size:12px;color:#334155;">Scan with the KiddieTrac app</div></div></body></html>`);
  await p.screenshot({ path: path.join(__dirname, 'assets', 'poster.png') });
  await b.close(); console.log('ok');
})();
