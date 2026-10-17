#!/bin/bash
# Host-side deploy of the shop tablets (2026-10-16). Run from ~/kiddietrac/marketing-assets/seo after
# uploading shop_tablets_edits.py, tr_shop_tablets.py, index.built.html.uploading and the six images to
# ~/public_html/images/shop/*.uploading. args: md5 of index.built.html, fr.json, es.json, hi.json
set -e
cd ~/kiddietrac/marketing-assets/seo
TS=$(date +%Y%m%d%H%M)
echo "$1  index.built.html" | md5sum -c -
echo "$2  $HOME/public_html/i18n/fr.json" | md5sum -c -
echo "$3  $HOME/public_html/i18n/es.json" | md5sum -c -
echo "$4  $HOME/public_html/i18n/hi.json" | md5sum -c -
tar czf ~/site-archive/pre-shop-tablets-$TS.tgz -C ~/public_html index.html p sections i18n sitemap.xml 404.shtml
cp index.built.html ~/site-archive/index.built.pre-shop-tablets-$TS.html
echo "archived: ~/site-archive/pre-shop-tablets-$TS.tgz"
python3 shop_tablets_edits.py index.built.html /tmp/st_built_$TS.html
cmp /tmp/st_built_$TS.html index.built.html.uploading
rm -f /tmp/st_built_$TS.html
for f in ~/public_html/images/shop/tablet-*.uploading; do mv "$f" "${f%.uploading}"; done
ls ~/public_html/images/shop/tablet-*
mkdir -p /tmp/st_i18n_$TS
python3 tr_shop_tablets.py ~/public_html/i18n /tmp/st_i18n_$TS
for l in fr es hi; do
  python3 -c "import json,sys;json.load(open(sys.argv[1],encoding='utf-8'))" /tmp/st_i18n_$TS/$l.json
  cp /tmp/st_i18n_$TS/$l.json ~/public_html/i18n/$l.json.uploading
  mv ~/public_html/i18n/$l.json.uploading ~/public_html/i18n/$l.json
done
rm -rf /tmp/st_i18n_$TS
mv index.built.html.uploading index.built.html
python3 seo_pages.py index.built.html ~/public_html | tail -2
