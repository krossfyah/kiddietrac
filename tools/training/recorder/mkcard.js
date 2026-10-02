const puppeteer = require('../demo/node_modules/puppeteer-core');
const fs = require('fs'), os = require('os'), path = require('path');
(async () => {
  const b = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, pipe: true, userDataDir: fs.mkdtempSync(path.join(os.tmpdir(), 'ktcard-')) });
  const p = await b.newPage(); await p.setViewport({ width: 900, height: 560, deviceScaleFactor: 1 });
  await p.setContent(`<html><body style="margin:0;background:#E8EEF3;font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;height:560px;">
  <div style="width:820px;background:#FFFDF6;border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.18);padding:26px 30px;border-top:10px solid #1F6080;">
   <div style="display:flex;justify-content:space-between;align-items:baseline;"><div style="font-size:24px;font-weight:800;color:#1F2937;">Immunization Record</div><div style="font-size:13px;color:#B91C1C;font-weight:700;letter-spacing:1px;">SAMPLE — FOR TRAINING ONLY</div></div>
   <div style="margin:8px 0 16px;font-size:15px;color:#374151;">Name: <b>Arjun Singh</b> &nbsp;·&nbsp; Date of birth: <b>2024-08-20</b></div>
   <table style="width:100%;border-collapse:collapse;font-size:15px;">
    <tr style="background:#F3F4F6;"><th style="text-align:left;padding:8px;">Vaccine</th><th style="text-align:left;padding:8px;">Date given</th><th style="text-align:left;padding:8px;">Clinic</th></tr>
    <tr><td style="padding:8px;border-top:1px solid #E5E7EB;">DTaP-IPV-Hib (1)</td><td style="padding:8px;border-top:1px solid #E5E7EB;">2024-10-21</td><td style="padding:8px;border-top:1px solid #E5E7EB;">Sample Clinic</td></tr>
    <tr><td style="padding:8px;border-top:1px solid #E5E7EB;">Pneumococcal (1)</td><td style="padding:8px;border-top:1px solid #E5E7EB;">2024-10-21</td><td style="padding:8px;border-top:1px solid #E5E7EB;">Sample Clinic</td></tr>
    <tr><td style="padding:8px;border-top:1px solid #E5E7EB;">MMR (1)</td><td style="padding:8px;border-top:1px solid #E5E7EB;">2025-08-22</td><td style="padding:8px;border-top:1px solid #E5E7EB;">Sample Clinic</td></tr>
   </table></div></body></html>`);
  await p.screenshot({ path: path.join(__dirname, 'assets', 'immunization-card.png') });
  await b.close(); console.log('ok');
})();
