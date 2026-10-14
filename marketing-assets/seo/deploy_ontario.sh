#!/bin/bash
# Host-side deploy of the Ontario features section (2026-10-13). Run from ~/kiddietrac/marketing-assets/seo
# after uploading: ontario_edits.py, tr_ontario.py, index.built.html.uploading,
# i18n_{fr,es,hi}.json.uploading (all into this directory). seo_meta.json is unchanged (no new page).
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
tar czf ~/site-archive/pre-ontario-$TS.tgz -C ~/public_html index.html p i18n sitemap.xml 404.shtml
cp index.built.html ~/site-archive/index.built.pre-ontario-$TS.html
echo "archived: ~/site-archive/pre-ontario-$TS.tgz"
# 2. the module must reproduce the uploaded build from the current base (python 3.6 here)
python3 ontario_edits.py index.built.html /tmp/on_built_$TS.html >/dev/null
cmp /tmp/on_built_$TS.html index.built.html.uploading
rm -f /tmp/on_built_$TS.html
echo "module output matches the uploaded build"
# 3. swap in (atomic renames)
mv index.built.html.uploading index.built.html
for l in fr es hi; do
  python3 -c "import json,sys;json.load(open(sys.argv[1],encoding='utf-8'))" i18n_$l.json.uploading
  mv i18n_$l.json.uploading ~/public_html/i18n/$l.json.uploading
  mv ~/public_html/i18n/$l.json.uploading ~/public_html/i18n/$l.json
done
# 4. generate every page
python3 seo_pages.py index.built.html ~/public_html | tail -3
