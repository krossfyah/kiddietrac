"""Merge tr_referrals.T into i18n/<lang>.json using the collector's key->template map.
usage: python merge_i18n.py missing.json in_dir out_dir"""
import json
import os
import re
import sys

from tr_referrals import T

missing = json.load(open(sys.argv[1], encoding='utf-8'))
src, dst = sys.argv[2], sys.argv[3]
os.makedirs(dst, exist_ok=True)
PH = re.compile(r'\{/?\d+/?\}')
for li, lang in enumerate(('fr', 'es', 'hi')):
    d = json.load(open(os.path.join(src, lang + '.json'), encoding='utf-8'))
    added = 0
    for key, tpl in missing[lang].items():
        assert tpl in T, ('no translation for', tpl[:90])
        tr = T[tpl][li]
        assert sorted(PH.findall(tr)) == sorted(PH.findall(tpl)), ('placeholders differ', lang, tpl[:60], tr[:60])
        if key.startswith('A|') or key.startswith('T|'):
            assert not PH.search(tr)
        if d.get(key) != tr:
            d[key] = tr
            added += 1
    unused = [k for k in T if k not in missing[lang].values()]
    if unused:
        print(lang, 'unused translations:', [u[:50] for u in unused])
    with open(os.path.join(dst, lang + '.json'), 'w', encoding='utf-8', newline='') as f:
        f.write(json.dumps(d, ensure_ascii=False, separators=(',', ':')))
    print(lang, 'added', added, 'total', len(d))
