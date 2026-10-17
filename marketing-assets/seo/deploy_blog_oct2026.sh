#!/bin/bash
# Host-side deploy of the October 2026 regulation articles (2026-10-16). Run from
# ~/kiddietrac/marketing-assets/seo after uploading blog_oct2026_edits.py, blog_oct2026_posts.json,
# tr_blog_oct2026.py and index.built.html.uploading into this directory.
# args: md5 of index.built.html, fr.json, es.json, hi.json (the bases the build was made from)
set -e
cd ~/kiddietrac/marketing-assets/seo
TS=$(date +%Y%m%d%H%M)
mkdir -p ~/site-archive
# 0. the bases must be the ones the build was made from
echo "$1  index.built.html" | md5sum -c -
echo "$2  $HOME/public_html/i18n/fr.json" | md5sum -c -
echo "$3  $HOME/public_html/i18n/es.json" | md5sum -c -
echo "$4  $HOME/public_html/i18n/hi.json" | md5sum -c -
# 1. archive (never inside public_html)
tar czf ~/site-archive/pre-blog-oct2026-$TS.tgz -C ~/public_html index.html p blog sections i18n sitemap.xml 404.shtml
cp index.built.html ~/site-archive/index.built.pre-blog-oct2026-$TS.html
cp page_state.json ~/site-archive/page_state.pre-blog-oct2026-$TS.json
echo "archived: ~/site-archive/pre-blog-oct2026-$TS.tgz"
# 2. the module must reproduce the uploaded build from the current base (python 3.6 here)
python3 blog_oct2026_edits.py index.built.html /tmp/bo_built_$TS.html
cmp /tmp/bo_built_$TS.html index.built.html.uploading
rm -f /tmp/bo_built_$TS.html
# 3. dictionaries: reworded changelog sentence, written beside the live files then renamed in
mkdir -p /tmp/bo_i18n_$TS
python3 tr_blog_oct2026.py ~/public_html/i18n /tmp/bo_i18n_$TS
for l in fr es hi; do
  python3 -c "import json,sys;json.load(open(sys.argv[1],encoding='utf-8'))" /tmp/bo_i18n_$TS/$l.json
  cp /tmp/bo_i18n_$TS/$l.json ~/public_html/i18n/$l.json.uploading
  mv ~/public_html/i18n/$l.json.uploading ~/public_html/i18n/$l.json
done
rm -rf /tmp/bo_i18n_$TS
# 4. swap in the build, then generate every page
mv index.built.html.uploading index.built.html
python3 seo_pages.py index.built.html ~/public_html | tail -3
