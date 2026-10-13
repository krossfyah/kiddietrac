#!/bin/bash
# Rebuild: host/index.built.html (fresh from the host) + referrals -> work/index.built.html,
# merged i18n, local site in work/site (served by serve.py on 8771).
set -e
D="C:/Users/djkro/AppData/Local/Temp/claude/C--Users-djkro/6b193d07-e9a3-4467-8c19-3909adedd0f5/scratchpad/referrals"
cd "$D"
cp host/seo_meta.json work/seo_meta.json
python referral_edits.py host/index.built.html work/index.built.html work/seo_meta.json
python collect.py host/index.built.html work/index.built.html i18n work/missing.json
PYTHONIOENCODING=utf-8 python merge_i18n.py work/missing.json i18n work/i18n
python collect.py host/index.built.html work/index.built.html work/i18n work/missing2.json
python - <<'X'
import re, subprocess
s = open('work/index.built.html', encoding='utf-8').read()
bad = 0
for n, m in enumerate(re.finditer(r'<script>(.*?)</script>', s, re.S)):
    open('work/_t.js', 'w', encoding='utf-8').write(m.group(1))
    r = subprocess.run(['node', '-e', 'require("vm").createScript(require("fs").readFileSync("work/_t.js","utf8"))'], capture_output=True, text=True)
    if r.returncode:
        bad += 1; print('BAD', n, r.stderr[:300])
print('script errors', bad)
X
grep -P -c "[\x00-\x08\x0b\x0c\x0e-\x1f]" work/index.built.html || true
mkdir -p work/site/i18n && rm -rf work/site/p work/site/blog && cp work/i18n/*.json work/site/i18n/
cp work/seo_meta.json gen/seo_meta.json
(cd gen && python seo_pages.py ../work/index.built.html ../work/site | tail -2)
