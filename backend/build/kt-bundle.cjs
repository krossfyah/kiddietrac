#!/usr/bin/env node
/* ═══════════════════════════════════════════════════════════════════════════════════════════
   KiddieTrac — publish dashboard.html with the always-loaded scripts BUNDLED (2026-11-28,
   portal speed step 4, Anthony approved).

   dashboard.src.html stays the file people edit, with one <script src="/js/x.js?v=…" defer>
   tag per file, and parent-portal/js/*.js stay the edit surface. This build step (run by
   ~/deploy.py right after it re-stamps the tags) writes dashboard.html with each run of
   consecutive plain `defer` tags replaced by ONE file, js/ktb-<n>-<hash>.js?v=<hash>.

   SAME ORDER: deferred scripts run after parsing, in document order. A bundle holds a run of
   consecutive defer tags in that order and sits where the first one was, so every file still
   runs exactly where it ran as a tag. Inline and non-defer scripts run at parse time, before
   any of them, either way.

   SAME ISOLATION: as tags, a file that throws while loading kills only itself. In a bundle each
   file sits in its own try { } block; a throw is logged and re-thrown on a timer, so it still
   reaches window.onerror (kt-crash-report) and the next file still runs. A try block is only
   neutral for a file with no top-level let/const/class/function and no "use strict" prologue
   (those would change scope or strictness inside a block); this script PARSES every file
   (acorn) and leaves any such file as its own tag. Also left as tags: files with any attribute
   besides `defer`, and files named in a lazy screen's "a" list (kt-screen-loader.js re-runs them
   by finding their tag).

   FAILS SAFE: any problem building => dashboard.html is a plain copy of dashboard.src.html
   (the unbundled tags), exactly what deploys did before this step.

   ROLLBACK, ONE LINE:  touch ~/applications/prxgmnggjv/public_html/kiddietrac/parent-portal/.kt-bundle-off
   then re-run `node backend/build/kt-bundle.cjs` (or any deploy.py run) — dashboard.html becomes the
   plain tags. `rm` the flag (and run again) to turn bundling back on. Env KT_BUNDLE=0 does the same
   for a single run.

   usage: node backend/build/kt-bundle.cjs [--check]   (from the repo root; --check builds nothing, prints the plan)
   ═══════════════════════════════════════════════════════════════════════════════════════════ */
'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = process.cwd();
const PP = path.join(ROOT, 'parent-portal');
const SRC = path.join(PP, 'dashboard.src.html');
const OUT = path.join(PP, 'dashboard.html');
const JS = path.join(PP, 'js');
const FLAG = path.join(PP, '.kt-bundle-off');
const CHUNK = 900 * 1024;           // split long runs so a phone downloads a few files in parallel
const KEEP_OLD_MS = 3 * 86400000;   // a tab still open on the previous build can fetch its bundles for 3 days
const CHECK = process.argv.includes('--check');

function atomicWrite(file, data) {
  const tmp = file + '.uploading';
  fs.writeFileSync(tmp, data);
  fs.renameSync(tmp, file);
}

function plain(src, why) {
  if (!CHECK) atomicWrite(OUT, src);
  console.log('  kt-bundle: OFF (' + why + ') - dashboard.html = dashboard.src.html, plain tags');
}

function main() {
  const src = fs.readFileSync(SRC, 'utf8');
  if (process.env.KT_BUNDLE === '0') return plain(src, 'KT_BUNDLE=0');
  if (fs.existsSync(FLAG)) return plain(src, path.relative(ROOT, FLAG) + ' present');
  let acorn;
  try { acorn = require('./vendor/acorn.cjs'); } catch (e) { return plain(src, 'acorn missing: ' + e.message); }

  // Lazy files' "a" lists name eager files the loader re-runs by their tag: those stay tags.
  const keepTag = new Set();
  const lm = src.match(/window\.__KT_LAZY=(\{.*?\});\s*\n/);
  if (lm) { try { const L = JSON.parse(lm[1]); Object.keys(L).forEach((k) => (L[k].a || []).forEach((f) => keepTag.add(f))); } catch (e) { return plain(src, 'could not read __KT_LAZY: ' + e.message); } }

  const re = /<script\b([^>]*)\bsrc="\/js\/([A-Za-z0-9._-]+\.js)\?v=([^"]*)"([^>]*)><\/script>/g;
  const tags = []; let m;
  while ((m = re.exec(src))) {
    const attrs = (m[1] + ' ' + m[4]).trim().replace(/\s+/g, ' ');
    tags.push({ start: m.index, end: m.index + m[0].length, name: m[2], ver: m[3], defer: /(^|\s)defer(\s|$)/.test(attrs), attrs });
  }
  const why = {};
  for (const t of tags) {
    if (!t.defer) continue;                                  // parse-time script: not part of the defer order at all
    if (t.attrs !== 'defer') { why[t.name] = 'attributes: ' + t.attrs; continue; }
    if (keepTag.has(t.name)) { why[t.name] = 're-run by kt-screen-loader (a)'; continue; }
    if (/^ktb-/.test(t.name)) { why[t.name] = 'already a bundle'; continue; }
    const f = path.join(JS, t.name);
    let code;
    try { code = fs.readFileSync(f, 'utf8'); } catch (e) { why[t.name] = 'missing'; continue; }
    let ast;
    try { ast = acorn.parse(code, { ecmaVersion: 'latest', sourceType: 'script', allowHashBang: false }); } catch (e) { why[t.name] = 'parse error ' + e.message; continue; }
    const top = [];
    for (const st of ast.body) {
      if (st.directive) top.push('directive "' + st.directive + '"');
      else if (st.type === 'VariableDeclaration') top.push('top-level ' + st.kind);
      else if (st.type === 'FunctionDeclaration') top.push('top-level function ' + (st.id && st.id.name));
      else if (st.type === 'ClassDeclaration') top.push('top-level class');
    }
    if (top.length) { why[t.name] = top.slice(0, 3).join(', '); continue; }
    if (/\bdocument\.currentScript\.src\b(?![^;]*origin)/.test(code) && !/currentScript[^;]{0,80}origin/.test(code)) { why[t.name] = 'reads its own <script> src'; continue; }
    t.ok = true; t.code = code;
  }

  // Runs of consecutive bundle-able DEFER tags (a non-bundle-able defer tag ends a run; parse-time tags do not).
  const runs = []; let cur = [];
  for (const t of tags) {
    if (!t.defer) continue;
    if (t.ok) { cur.push(t); } else { if (cur.length) runs.push(cur); cur = []; }
  }
  if (cur.length) runs.push(cur);
  const groups = [];
  for (const r of runs) {
    let g = [], size = 0;
    for (const t of r) {
      if (g.length && size + t.code.length > CHUNK) { groups.push(g); g = []; size = 0; }
      g.push(t); size += t.code.length;
    }
    if (g.length) groups.push(g);
  }
  const single = groups.filter((g) => g.length === 1).length;

  let html = src; const edits = []; const written = [];
  groups.forEach((g, i) => {
    if (g.length < 2) return;   // one file: keep its own tag
    let body = '/* KiddieTrac bundle (backend/build/kt-bundle.cjs). BUILD OUTPUT - edit parent-portal/js/<file>, never this. */\n';
    for (const t of g) {
      const n = JSON.stringify(t.name);
      body += '/* ==== js/' + t.name + '?v=' + t.ver + ' ==== */\ntry {\n' + t.code + '\n;} catch (__ktE) { try { (window.__ktBundleErr = window.__ktBundleErr || []).push([' + n + ', String(__ktE)]); console.error("[kt-bundle] " + ' + n + ', __ktE); } catch (__ktX) {} setTimeout(function () { throw __ktE; }, 0); }\n';
    }
    // Same content, same name: a rebuild with no change keeps every client's cached copy.
    const hash = crypto.createHash('md5').update(body).digest('hex').slice(0, 12);
    const name = 'ktb-' + (i + 1) + '-' + hash + '.js';
    written.push([name, body, g]);
    const tag = '<script src="/js/' + name + '?v=' + hash + '" defer data-kt-bundle="' + g.length + '"></script>';
    edits.push([g[0].start, g[0].end, tag]);
    for (const t of g.slice(1)) edits.push([t.start, t.end, '']);
  });
  if (!written.length) return plain(src, 'nothing to bundle');
  edits.sort((a, b) => b[0] - a[0]);
  for (const [s, e, rep] of edits) {
    // drop the whole line when the tag was alone on it
    let ls = s, le = e;
    if (rep === '') {
      while (ls > 0 && (html[ls - 1] === ' ' || html[ls - 1] === '\t')) ls--;
      if ((ls === 0 || html[ls - 1] === '\n') && html[le] === '\n') { le++; } else { ls = s; }
    }
    html = html.slice(0, ls) + rep + html.slice(le);
  }
  html = html.replace(/<!DOCTYPE html>/i, (d) => d + '\n<!-- BUILD OUTPUT of backend/build/kt-bundle.cjs from dashboard.src.html - edit dashboard.src.html, never this file. Rollback: touch parent-portal/.kt-bundle-off and re-run. -->');

  const files = written.reduce((a, w) => a + w[2].length, 0);
  console.log('  kt-bundle: ' + files + ' files -> ' + written.length + ' bundles; ' + Object.keys(why).length + ' defer files kept as tags' + (single ? ' (+' + single + ' lone)' : ''));
  for (const k of Object.keys(why)) console.log('    tag kept: ' + k + ' - ' + why[k]);
  if (CHECK) { written.forEach((w) => console.log('    ' + w[0] + ' ' + Math.round(w[1].length / 1024) + ' KB, ' + w[2].length + ' files')); return; }

  for (const [name, body] of written) {
    const f = path.join(JS, name);
    if (!fs.existsSync(f)) atomicWrite(f, body);
    fs.utimesSync(f, new Date(), new Date());   // still in use: never swept below
  }
  atomicWrite(OUT, html);
  console.log('  dashboard.html published BUNDLED (' + written.map((w) => w[0]).join(', ') + ')');
  // Sweep bundles no page has referenced for KEEP_OLD_MS.
  const live = new Set(written.map((w) => w[0]));
  for (const f of fs.readdirSync(JS)) {
    if (!/^ktb-\d+-[0-9a-f]{12}\.js$/.test(f) || live.has(f)) continue;
    const p = path.join(JS, f);
    try { if (Date.now() - fs.statSync(p).mtimeMs > KEEP_OLD_MS) fs.unlinkSync(p); } catch (e) {}
  }
}

try { main(); } catch (e) {
  console.log('  kt-bundle: FAILED (' + (e && e.message) + ')');
  try { plain(fs.readFileSync(SRC, 'utf8'), 'build failed'); } catch (e2) { console.log('  kt-bundle: could not even copy dashboard.src.html: ' + e2.message); process.exitCode = 1; }
}
