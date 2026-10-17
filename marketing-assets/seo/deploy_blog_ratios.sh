#!/bin/bash
# Host-side deploy of the Ontario ratio rules article (2026-10-16). Run from
# ~/kiddietrac/marketing-assets/seo after uploading blog_ratios_edits.py, blog_ratios_posts.json,
# tr_blog_ratios.py, src_ratios.py and index.built.html.uploading.
# args: md5 of index.built.html, fr.json, es.json, hi.json (the bases the build was made from)
set -e
cd ~/kiddietrac/marketing-assets/seo
TS=$(date +%Y%m%d%H%M)
echo "$1  index.built.html" | md5sum -c -
echo "$2  $HOME/public_html/i18n/fr.json" | md5sum -c -
echo "$3  $HOME/public_html/i18n/es.json" | md5sum -c -
echo "$4  $HOME/public_html/i18n/hi.json" | md5sum -c -
tar czf ~/site-archive/pre-blog-ratios-$TS.tgz -C ~/public_html index.html p blog sections i18n sitemap.xml 404.shtml
cp index.built.html ~/site-archive/index.built.pre-blog-ratios-$TS.html
cp ontario_edits.py related_edits.py ~/site-archive/
echo "archived: ~/site-archive/pre-blog-ratios-$TS.tgz"
# the module must reproduce the uploaded build from the current base
python3 blog_ratios_edits.py index.built.html /tmp/br_built_$TS.html
cmp /tmp/br_built_$TS.html index.built.html.uploading
rm -f /tmp/br_built_$TS.html
# the earlier modules write the new wording themselves from now on
python3 src_ratios.py
# dictionaries
mkdir -p /tmp/br_i18n_$TS
python3 tr_blog_ratios.py ~/public_html/i18n /tmp/br_i18n_$TS
for l in fr es hi; do
  python3 -c "import json,sys;json.load(open(sys.argv[1],encoding='utf-8'))" /tmp/br_i18n_$TS/$l.json
  cp /tmp/br_i18n_$TS/$l.json ~/public_html/i18n/$l.json.uploading
  mv ~/public_html/i18n/$l.json.uploading ~/public_html/i18n/$l.json
done
rm -rf /tmp/br_i18n_$TS
mv index.built.html.uploading index.built.html
python3 seo_pages.py index.built.html ~/public_html | tail -3
