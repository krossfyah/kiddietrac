<?php
/**
 * KiddieTrac parent-portal JS bundler
 * ==================================================================
 * WHY: dashboard.html loads ~162 separate `defer` <script> files. Over
 * HTTP/2 the *network* cost is small, but the browser still has to parse +
 * execute 162 separate scripts in order on the main thread — that is the
 * felt "slight delay" on load, worst on the APK WebView / mid-range Android.
 * Concatenating them into ONE file removes 161 script-boundary costs.
 *
 * HOW IT WORKS
 *   dashboard.src.html   <-- CANONICAL SOURCE. Edit THIS file. It keeps the
 *                            162 individual <script> tags.
 *   dashboard.html       <-- GENERATED. Do NOT hand-edit; it is overwritten.
 *   js/app.bundle.js     <-- GENERATED. Concatenation of the 162 files, in
 *                            document order, served as /js/app.bundle.js?v=<md5>
 *
 * The ?v=<md5> is the md5 of the bundle contents, so:
 *   - .htaccess gives it `Cache-Control: immutable` (the rule keys on ?v=)
 *   - service-worker.js treats it as a versioned asset (cache-first, persistent)
 *   - a content change = a new md5 = a new URL = guaranteed cache miss
 * i.e. the bundle can never go stale on a client. It can only go stale ON THE
 * SERVER, if someone scp's a js file and forgets to rebuild — which is what
 * `--if-stale` (run from cron every minute) exists to prevent.
 *
 * ORDER SAFETY: only `defer` scripts are bundled. Deferred scripts always run
 * after parsing, in document order, so the interleaved INLINE <script> blocks
 * (which run at parse time, i.e. before all of them) are unaffected. The bundle
 * tag is emitted at the position of the FIRST bundled tag.
 *
 * USAGE
 *   php build-bundle.php               # always rebuild
 *   php build-bundle.php --if-stale    # rebuild only if a source is newer (cron)
 *   php build-bundle.php --check       # exit 1 if stale, write nothing
 *   php build-bundle.php --out=FILE    # write the HTML somewhere else (staging)
 * ==================================================================
 */

$DIR      = __DIR__;
$SRC      = $DIR . '/dashboard.src.html';
$JS_DIR   = $DIR . '/js';
$BUNDLE   = $JS_DIR . '/app.bundle.js';

$argvv    = $argv ?? [];
$ifStale  = in_array('--if-stale', $argvv, true);
$checkOnly= in_array('--check', $argvv, true);
$outFile  = $DIR . '/dashboard.html';
foreach ($argvv as $a) {
    if (strpos($a, '--out=') === 0) {
        $outFile = $DIR . '/' . basename(substr($a, 6));
    }
}

function fail($msg) { fwrite(STDERR, "build-bundle: ERROR: $msg\n"); exit(2); }
function info($msg) { fwrite(STDOUT, "build-bundle: $msg\n"); }

if (!is_file($SRC)) {
    fail("missing $SRC — create it with:  cp dashboard.html dashboard.src.html");
}

$html = file_get_contents($SRC);
if ($html === false || strlen($html) < 1000) fail('dashboard.src.html unreadable or implausibly small');

// ── 1. Collect the ordered list of local, deferred js files ───────────────
$re = '#[ \t]*<script\s+src="(/?js/[^"]+?\.js)(\?[^"]*)?"\s+defer\s*></script>[ \t]*\r?\n?#i';
if (!preg_match_all($re, $html, $m, PREG_SET_ORDER)) {
    fail('no bundleable <script src="/js/....js" defer> tags found in dashboard.src.html');
}

$files = [];
foreach ($m as $hit) {
    $rel  = ltrim($hit[1], '/');            // "js/app.js"
    $path = $DIR . '/' . $rel;
    if (!is_file($path)) fail("referenced but missing on disk: $rel");
    $files[] = ['rel' => $rel, 'path' => $path];
}
$count = count($files);
if ($count < 100) fail("only $count files matched — refusing to build a suspiciously small bundle");

// ── 2. Staleness check ────────────────────────────────────────────────────
$bundleMtime = is_file($BUNDLE) ? filemtime($BUNDLE) : 0;
$newest = filemtime($SRC);
$newestName = 'dashboard.src.html';
foreach ($files as $f) {
    $mt = filemtime($f['path']);
    if ($mt > $newest) { $newest = $mt; $newestName = $f['rel']; }
}
$stale = ($bundleMtime === 0) || ($newest > $bundleMtime) || !is_file($outFile);

if ($checkOnly) {
    if ($stale) {
        info("STALE — newest input is $newestName (" . date('Y-m-d H:i:s', $newest) .
             "), bundle built " . ($bundleMtime ? date('Y-m-d H:i:s', $bundleMtime) : 'never'));
        exit(1);
    }
    info("fresh — bundle is newer than all $count sources");
    exit(0);
}
if ($ifStale && !$stale) exit(0);

// ── 3. Concatenate ────────────────────────────────────────────────────────
// A leading ";" between files guards against ASI hazards (a file ending without
// a semicolon followed by one starting with "(" or "[").
$body = '';
foreach ($files as $f) {
    $code = file_get_contents($f['path']);
    if ($code === false) fail('could not read ' . $f['rel']);
    $body .= "\n;/* ===== " . $f['rel'] . " ===== */\n" . $code . "\n";
}

// Hash the CODE ONLY — never the build timestamp. Otherwise every rebuild would
// mint a new ?v= and needlessly bust every client's immutable cache even when
// not a single source byte changed.
$md5 = substr(md5($body), 0, 12);

$out = "/* KiddieTrac bundle — GENERATED by build-bundle.php. Do not edit.\n"
     . " * $count files, in dashboard.src.html order. v=$md5\n"
     . " * Built " . date('c') . " (timestamp excluded from the hash) */\n"
     . $body;

// ── 4. Rewrite the HTML: first tag -> bundle tag, rest removed ────────────
$emitted = false;
$newHtml = preg_replace_callback($re, function ($hit) use (&$emitted, $md5, $count) {
    if ($emitted) return '';
    $emitted = true;
    return "  <!-- $count portal scripts, bundled by build-bundle.php. "
         . "Edit dashboard.src.html, never this file. -->\n"
         . "  <script src=\"/js/app.bundle.js?v=$md5\" defer></script>\n";
}, $html);

if ($newHtml === null || !$emitted) fail('HTML rewrite failed');

$banner = "<!-- ==========================================================\n"
        . "     GENERATED FILE — DO NOT EDIT.\n"
        . "     Source: dashboard.src.html   Builder: build-bundle.php\n"
        . "     Any hand-edit here is destroyed on the next build.\n"
        . "     ========================================================== -->\n";
if (stripos(ltrim($newHtml), '<!doctype') === 0) {
    $newHtml = preg_replace('/(<!doctype[^>]*>\r?\n?)/i', '$1' . $banner, $newHtml, 1);
} else {
    $newHtml = $banner . $newHtml;
}

// ── 5. Write atomically ───────────────────────────────────────────────────
$tmp = $BUNDLE . '.tmp';
if (file_put_contents($tmp, $out) === false) fail('could not write bundle temp file');
if (!rename($tmp, $BUNDLE)) fail('could not move bundle into place');
@chmod($BUNDLE, 0644);

$tmpH = $outFile . '.tmp';
if (file_put_contents($tmpH, $newHtml) === false) fail('could not write html temp file');
if (!rename($tmpH, $outFile)) fail('could not move html into place');
@chmod($outFile, 0644);

info(sprintf('built %s (%d files, %s KB, v=%s) -> %s',
    basename($BUNDLE), $count, number_format(strlen($out) / 1024), $md5, basename($outFile)));
