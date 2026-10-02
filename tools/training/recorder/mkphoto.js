const puppeteer = require('../demo/node_modules/puppeteer-core');
const fs = require('fs'), os = require('os'), path = require('path');
(async () => {
  const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, pipe: true, userDataDir: fs.mkdtempSync(path.join(os.tmpdir(), 'ktph-')) });
  const p = await b.newPage(); await p.setViewport({ width: 1200, height: 900, deviceScaleFactor: 1 });
  const blocks = [['#EF4444', 0], ['#F59E0B', 1], ['#10B981', 2], ['#3B82F6', 3], ['#8B5CF6', 4]].map(([c, i]) => `<div style="position:absolute;left:${470 + (i % 2) * 18}px;bottom:${150 + i * 112}px;width:260px;height:104px;background:${c};border-radius:10px;box-shadow:inset 0 -10px 0 rgba(0,0,0,.12),0 6px 14px rgba(0,0,0,.18);"></div>`).join('');
  await p.setContent(`<html><body style="margin:0;width:1200px;height:900px;background:linear-gradient(180deg,#FDE68A 0%,#FEF3C7 62%,#D6A26A 62%,#C8935C 100%);position:relative;overflow:hidden;">
   <div style="position:absolute;left:80px;top:90px;width:180px;height:180px;border-radius:50%;background:#FBBF24;opacity:.6"></div>${blocks}
   <div style="position:absolute;right:120px;bottom:150px;width:90px;height:90px;background:#F472B6;border-radius:16px;transform:rotate(18deg);box-shadow:0 6px 14px rgba(0,0,0,.18)"></div></body></html>`);
  await p.screenshot({ path: path.join(__dirname, 'assets', 'block-tower.jpg'), type: 'jpeg', quality: 85 });
  await b.close(); console.log('ok');
})();
