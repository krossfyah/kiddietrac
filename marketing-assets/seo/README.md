# Marketing site SEO (2026-09-30)

Every address on www.kiddietrac.com has its own pre-rendered HTML file, so search engines
see one page per address instead of the home page 44 times.

## Deploying the marketing site (changed)

1. Build as before: `python build.py live` (the chain ends with `seo_edits.py`).
2. Upload `index.new.html` to `~/kiddietrac/marketing-assets/seo/index.built.html`.
3. On the server:

       cd ~/kiddietrac/marketing-assets/seo
       cp ~/public_html/index.html ~/public_html/backups/index.html.bak-$(date +%Y%m%d%H%M)
       python3 seo_pages.py index.built.html ~/public_html

   That writes `index.html`, `p/**` (every page x en/fr/es/hi, every article),
   `blog/body/*.html` (article bodies, moved out of the page) and `sitemap.xml`.

**Do not upload index.new.html straight over index.html any more.** The site would still
work (bodies inline), but the `/p` pages would be stale and the page would double in size.

## Pieces

- `seo_meta.json`: page titles/descriptions in fr/es/hi (English comes from the page's META table).
- `seo_edits.py`: the page's own JavaScript: /fr /es /hi prefixes, canonical and hreflang on
  navigation, language switch moves the address, crawlable hrefs, blog bodies on demand.
- `seo_pages.py`: the generator (Python 3.6).
- `.htaccess` (public_html, block `KT-SEO`): the bare domain goes to www; `/x` is served from `p/x.html`.

A new page needs an entry in the page's META table (English) and in `seo_meta.json` (fr/es/hi).
seo_pages.py refuses to run if a page has no English title.

## One section per file (2026-10-16)

- **Each file holds only its own page section.** The other 35 sections are empty `<!--kt-lazy-->` placeholders, fetched from `/sections/<lang>/<page>.html?v=<build>` just before `showPage` shows them. Before this, every address carried all 18,600 words and Google saw near-duplicates.
- **The loader** is `LOADER_HEAD` / `LOADER_TAIL` in `seo_pages.py`. The tail installs the outermost `showPage` wrapper; it is the last script in the body.
- **Start-up scripts:** an inline script that sets up a lazy section at load (and does not touch `showPage`) gets `data-kt-rerun="<pages>"`. It runs again when that section arrives, after its group's other sections are put in (the two calculators share one script).
- **`FULL_NAV`** (home, shop) lists sections whose set-up runs only at load and also wraps `showPage`. From another page they get a normal page load of their own address.
- **`KEEP_INLINE`** keeps a section in every file. It is empty today.
- **Test before deploying:** run `KT_SEO_LANGS=en python seo_pages.py index.built.html <dir>` and serve the result locally. Then call `showPage(<every id>)` and check that the shop, the calculators and the blog cards work.
- **`page_state.json`** holds a hash of each address's content and the day it changed, so the sitemap's `lastmod` only moves when content does. `/terms` is not listed; `/privacy/` is listed with its slash because it is a directory.
- **Fixes applied in the generator, so they survive rebuilds:**
  - the Organization `sameAs` comes from `SAME_AS`;
  - `DEAD_SOCIAL` footer buttons are removed;
  - `aggregateRating` is dropped until the reviews are visible on the page;
  - blog titles end ` | KiddieTrac` only when the title is 48 characters or fewer.

## WebP and caching (.htaccess KT-WEBP / KT-CACHE / KT-ONEHOP)

- **WebP:** `<image>.jpg.webp` sits beside the original. Browsers that accept WebP are served it (`Vary: Accept`). **If you replace an image, regenerate its .webp** with Pillow at quality 80, or lossless for PNG; otherwise the old picture keeps being served.
- **Cache times:** images 7 days, fonts 1 year, `/sections/` 1 year (the build stamp in `?v=` busts it).
- **Redirects:** the bare domain goes to `https://www` in one redirect. `/.well-known/assetlinks.json` still answers on the bare domain.
