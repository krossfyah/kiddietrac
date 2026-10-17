#!/bin/bash
# Host-side deploy of the related-article cards (2026-10-16). Run from
# ~/kiddietrac/marketing-assets/seo after uploading related_edits.py, seo_pages.py.uploading and
# index.built.html.uploading. arg: md5 of the current index.built.html (the build's base)
set -e
cd ~/kiddietrac/marketing-assets/seo
TS=$(date +%Y%m%d%H%M)
echo "$1  index.built.html" | md5sum -c -
tar czf ~/site-archive/pre-related-$TS.tgz -C ~/public_html index.html p sections sitemap.xml 404.shtml
cp index.built.html ~/site-archive/index.built.pre-related-$TS.html
cp seo_pages.py ~/site-archive/seo_pages.pre-related-$TS.py
echo "archived: ~/site-archive/pre-related-$TS.tgz"
python3 related_edits.py index.built.html /tmp/rel_built_$TS.html
cmp /tmp/rel_built_$TS.html index.built.html.uploading
rm -f /tmp/rel_built_$TS.html
python3 -m py_compile seo_pages.py.uploading
mv seo_pages.py.uploading seo_pages.py
mv index.built.html.uploading index.built.html
python3 seo_pages.py index.built.html ~/public_html | tail -3
