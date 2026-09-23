# Help & tour screenshots

Captured from **Test Agency (agency 6) only**. Never from a live customer.

On 2026-08-19 the published set was found to have been taken from the live iLearn
agency and served from a public, unauthenticated URL: fifteen named children with ages
and family names, and home-childcare providers listed against their residential
addresses. Those files are quarantined at `~/help-img-quarantine-2026-08-19` on the
host and must not be restored.

## Re-running

    php artisan tinker --execute="echo App\Models\User::find(1)->createToken('help-capture')->plainTextToken;"
    cd tools/help-screenshots && npm install puppeteer-core

THE HOST HAS NO NODE. This has always run on a workstation with the PNGs uploaded
afterwards, so capture.js uses puppeteer-core against the Chrome already installed
there (set CHROME_PATH to override) rather than pulling a second ~150MB Chromium to
do the same job.

Seven shots take about nine minutes -- every one reloads the whole 5MB app. Run it in
the background, and check file sizes afterwards per the cautions below.
    KT_TOKEN="<token>" node capture.js
    scp out/*.png      <host>:~/kiddietrac/backend/public/help-img/
    scp out/tour/*.png <host>:~/kiddietrac/backend/public/help-img/tour/

Then delete the token.

`clean.js` strips what a customer never sees, before each shot: the PLATFORM and SALES
nav groups, the SUPER ADMIN badge, the View-as pill, the phone bottom bar, and the
greeting name. It matches on what things ARE — an href, the letters of a label, a
pinned element — rather than on class names, which change between builds.

Two cautions learned the hard way:

- Matching a nav heading on its exact text fails: it renders as "🌐 PLATFORM ▾", so
  compare letters only.
- Do not climb to the nearest pinned ancestor to hide the View-as pill. The app shell
  is pinned, so that hides the whole page and every screenshot comes out blank white at
  about 19KB. Check file sizes after a run; a uniform tiny size means the page was
  hidden, and a uniform large size means every shot captured the same thing.

2026-09-04 -- THE VIEW-AS PILL IS FIXED. It survived every earlier run for exactly the
reason the first caution above gives, one step further along: clean.js matched it with
/^View as/i, which cannot match an eye-icon prefix any more than an exact match can
match a globe-icon prefix on PLATFORM. It is compared on LETTERS ONLY now, like
everything else in that file.

Known cosmetic gap: the empty PLATFORM and SALES group headings can still survive. The
label sweep was widened from .app-sidebar to the whole document, which did not shift
them, so the heading is probably failing the "contains no link" or small-subtree
guard. It carries no customer data. Do NOT relax those two guards to chase it -- they
are what stops the sweep swallowing a nav group that still has navigation in it.
