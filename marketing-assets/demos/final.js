// Final screens for the tour + 3D showcase, with the hotspot each tour step points at.
const H = require('./harness');
const fs = require('fs');
const path = require('path');

const STEPS = [
  { id: 'overview', who: 'admin', hash: 'dashboard', find: /^RECEIVABLES$/ , up: 1 },
  { id: 'day', who: 'admin', hash: 'provider-day', find: /^ATTENDED$|^CHECKED IN$|^PRESENT$/i, up: 2 },
  { id: 'ratios', who: 'admin', hash: 'room-ratios', find: /COMPLIANT/, up: 0 },
  { id: 'log', who: 'admin', hash: 'care-log', find: /^Nap$/, up: 1 },
  { id: 'arrival', who: 'admin', hash: 'safe-arrival', find: /^Needs attention$/i, up: 0 },
  { id: 'billing', who: 'admin', hash: 'external-billing', find: /^OUTSTANDING$/i, up: 1 },

  { id: 'p-home', who: 'parent', hash: 'home', find: /^QUICK ACCESS$/i, up: 1, m: true },
  { id: 'p-today', who: 'parent', hash: 'today', find: /^YOUR EDUCATOR$/i, up: 2, m: true },
  { id: 'p-messages', who: 'parent', hash: 'messages', find: /nut-free/i, up: 2, m: true },
  { id: 'p-billing', who: 'parent', hash: 'billing', find: /^Ways to pay$/i, up: 1, m: true },
];

(async () => {
  const out = path.join(__dirname, 'assets');
  fs.mkdirSync(out, { recursive: true });
  const b = await H.browser();
  const pages = {};
  const meta = {};
  for (const s of STEPS) {
    const key = s.who + (s.m ? 'm' : 'd');
    if (!pages[key]) { pages[key] = await H.newPage(b, !!s.m); await H.login(pages[key], s.who, s.who === 'admin' ? 'agency_admin' : null); }
    const p = pages[key];
    await H.go(p, s.hash, 6500);
    const rect = await p.evaluate((src, flags, up) => {
      const re = new RegExp(src, flags);
      const el = [...document.querySelectorAll('#appMain *, main *, body *')].find(e => e.offsetParent && e.children.length === 0 && re.test((e.textContent || '').trim()));
      if (!el) return null;
      let t = el; for (let i = 0; i < up && t.parentElement; i++) t = t.parentElement;
      const r = t.getBoundingClientRect();
      return { x: r.left / innerWidth, y: r.top / innerHeight, w: r.width / innerWidth, h: r.height / innerHeight };
    }, s.find.source, s.find.flags, s.up);
    await p.screenshot({ path: path.join(out, s.id + '.png') });
    meta[s.id] = rect;
    console.log(s.id, rect ? Object.values(rect).map(v => v.toFixed(3)).join(',') : 'NO HOTSPOT');
  }
  fs.writeFileSync(path.join(out, 'hotspots.json'), JSON.stringify(meta, null, 1));
  await b.close();
})().catch(e => { console.log('ERR', e.message); process.exit(1); });
