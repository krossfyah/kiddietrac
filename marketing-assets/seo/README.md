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
