# -*- coding: utf-8 -*-
"""Image descriptions for the card acceptance marks (paymarks_edits.py, 2026-10-20). Brand names,
the same in fr/es/hi. usage: python tr_paymarks.py <i18n dir in> <i18n dir out>"""
import io, json, os, sys
T = {'A|Visa': 'Visa', 'A|Mastercard': 'Mastercard', 'A|Interac': 'Interac'}
src, dst = sys.argv[1], sys.argv[2]
for lang in ('fr', 'es', 'hi'):
    d = json.load(io.open(os.path.join(src, lang + '.json'), encoding='utf-8'))
    for k, v in T.items():
        d.setdefault(k, v)
    with io.open(os.path.join(dst, lang + '.json'), 'w', encoding='utf-8') as f:
        f.write(json.dumps(d, ensure_ascii=False, separators=(',', ':')))
    print(lang, len(d))
