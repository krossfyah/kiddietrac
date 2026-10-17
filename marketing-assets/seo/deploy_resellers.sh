#!/bin/bash
# Host-side deploy of the reseller program (2026-10-16). Run from ~/kiddietrac/marketing-assets/seo
# after uploading reseller_edits.py, tr_resellers.py, resellers_merge_i18n.py, index.built.html.uploading,
# seo_meta.json.uploading, i18n_{fr,es,hi}.json.uploading.
# args: md5 of index.built.html, seo_meta.json, fr.json, es.json, hi.json (the bases)
set -e
cd ~/kiddietrac/marketing-assets/seo
TS=$(date +%Y%m%d%H%M)
echo "$1  index.built.html" | md5sum -c -
echo "$2  seo_meta.json" | md5sum -c -
echo "$3  $HOME/public_html/i18n/fr.json" | md5sum -c -
echo "$4  $HOME/public_html/i18n/es.json" | md5sum -c -
echo "$5  $HOME/public_html/i18n/hi.json" | md5sum -c -
tar czf ~/site-archive/pre-resellers-$TS.tgz -C ~/public_html index.html p sections i18n sitemap.xml 404.shtml
cp index.built.html ~/site-archive/index.built.pre-resellers-$TS.html
cp seo_meta.json ~/site-archive/seo_meta.pre-resellers-$TS.json
echo "archived: ~/site-archive/pre-resellers-$TS.tgz"
cp seo_meta.json /tmp/rs_meta_$TS.json
python3 reseller_edits.py index.built.html /tmp/rs_built_$TS.html /tmp/rs_meta_$TS.json >/dev/null
cmp /tmp/rs_built_$TS.html index.built.html.uploading
cmp /tmp/rs_meta_$TS.json seo_meta.json.uploading
rm -f /tmp/rs_built_$TS.html /tmp/rs_meta_$TS.json
echo "module output matches the uploaded build"
mv index.built.html.uploading index.built.html
mv seo_meta.json.uploading seo_meta.json
for l in fr es hi; do
  python3 -c "import json,sys;json.load(open(sys.argv[1],encoding='utf-8'))" i18n_$l.json.uploading
  mv i18n_$l.json.uploading ~/public_html/i18n/$l.json.uploading
  mv ~/public_html/i18n/$l.json.uploading ~/public_html/i18n/$l.json
done
python3 seo_pages.py index.built.html ~/public_html | tail -3
