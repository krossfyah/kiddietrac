// Play Spark picture: SVG (stdin) -> PNG (stdout) with resvg. Usage: node svg-png.cjs <widthPx>
// Run by the queue worker only (web PHP cannot start processes). No network, no file access beyond fonts.
'use strict';
const { Resvg } = require('@resvg/resvg-js');
const width = Math.max(200, Math.min(2400, parseInt(process.argv[2] || '1200', 10) || 1200));
const chunks = [];
process.stdin.on('data', (c) => chunks.push(c));
process.stdin.on('end', () => {
  try {
    const svg = Buffer.concat(chunks).toString('utf8');
    const r = new Resvg(svg, {
      fitTo: { mode: 'width', value: width },
      background: '#ffffff',
      font: { loadSystemFonts: true, defaultFontFamily: 'Lato', sansSerifFamily: 'Lato' },
      imageRendering: 0, shapeRendering: 2, textRendering: 1,
    });
    process.stdout.write(r.render().asPng());
  } catch (e) {
    process.stderr.write(String(e && e.message || e));
    process.exit(1);
  }
});
