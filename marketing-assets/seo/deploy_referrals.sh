#!/bin/bash
# Host-side deploy of the referral program page (2026-10-13). Run from ~/kiddietrac/marketing-assets/seo
# after uploading: referral_edits.py, tr_referrals.py, index.built.html.uploading,
# seo_meta.json.uploading, i18n_{fr,es,hi}.json.uploading (all into this directory).
set -e
cd ~/kiddietrac/marketing-assets/seo
TS=$(date +%Y%m%d%H%M)
mkdir -p ~/site-archive
# 1. archive (never inside public_html)
tar czf ~/site-archive/pre-referrals-$TS.tgz -C ~/public_html index.html p i18n sitemap.xml 404.shtml
cp index.built.html ~/site-archive/index.built.pre-referrals-$TS.html
cp seo_meta.json ~/site-archive/seo_meta.pre-referrals-$TS.json
echo "archived: ~/site-archive/pre-referrals-$TS.tgz"
# 2. the module must reproduce the uploaded build from the current base (python 3.6 here)
cp seo_meta.json /tmp/ref_meta_$TS.json
python3 referral_edits.py index.built.html /tmp/ref_built_$TS.html /tmp/ref_meta_$TS.json >/dev/null
cmp /tmp/ref_built_$TS.html index.built.html.uploading
cmp /tmp/ref_meta_$TS.json seo_meta.json.uploading
rm -f /tmp/ref_built_$TS.html /tmp/ref_meta_$TS.json
echo "module output matches the uploaded build"
# 3. swap in (atomic renames)
mv index.built.html.uploading index.built.html
mv seo_meta.json.uploading seo_meta.json
for l in fr es hi; do
  python3 -c "import json,sys;json.load(open(sys.argv[1],encoding='utf-8'))" i18n_$l.json.uploading
  mv i18n_$l.json.uploading ~/public_html/i18n/$l.json.uploading
  mv ~/public_html/i18n/$l.json.uploading ~/public_html/i18n/$l.json
done
# 4. generate every page
python3 seo_pages.py index.built.html ~/public_html | tail -3
