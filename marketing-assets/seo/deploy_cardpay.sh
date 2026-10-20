#!/bin/bash
# Host-side deploy of the Wallet card-payments section (2026-10-20). Run from
# ~/kiddietrac/marketing-assets/seo after uploading cardpay_edits.py, wallet.svg, tr_cardpay.py
# and index.built.html.uploading. args: md5 of index.built.html, fr.json, es.json, hi.json (bases)
set -e
cd ~/kiddietrac/marketing-assets/seo
TS=$(date +%Y%m%d%H%M)
echo "$1  index.built.html" | md5sum -c -
echo "$2  $HOME/public_html/i18n/fr.json" | md5sum -c -
echo "$3  $HOME/public_html/i18n/es.json" | md5sum -c -
echo "$4  $HOME/public_html/i18n/hi.json" | md5sum -c -
tar czf ~/site-archive/pre-cardpay-$TS.tgz -C ~/public_html index.html p sections i18n sitemap.xml 404.shtml
cp index.built.html ~/site-archive/index.built.pre-cardpay-$TS.html
echo "archived: ~/site-archive/pre-cardpay-$TS.tgz"
python3 cardpay_edits.py index.built.html /tmp/cp_built_$TS.html
cmp /tmp/cp_built_$TS.html index.built.html.uploading
rm -f /tmp/cp_built_$TS.html
echo "module output matches the uploaded build"
mkdir -p /tmp/cp_i18n_$TS
python3 tr_cardpay.py ~/public_html/i18n /tmp/cp_i18n_$TS
for l in fr es hi; do
  python3 -c "import json,sys;json.load(open(sys.argv[1],encoding='utf-8'))" /tmp/cp_i18n_$TS/$l.json
  cp /tmp/cp_i18n_$TS/$l.json ~/public_html/i18n/$l.json.uploading
  mv ~/public_html/i18n/$l.json.uploading ~/public_html/i18n/$l.json
done
rm -rf /tmp/cp_i18n_$TS
mv index.built.html.uploading index.built.html
python3 seo_pages.py index.built.html ~/public_html | tail -2
