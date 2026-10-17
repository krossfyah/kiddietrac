# -*- coding: utf-8 -*-
"""fr/es/hi for the reworded Ontario ratio card (blog_ratios_edits.py). Keys are the English text.

usage: python tr_blog_ratios.py <i18n dir in> <i18n dir out>
"""
import io
import json
import os
import sys

TAIL = 'Directors and educators see the same live result.'
OLD = ('|Each room at a licensed centre is checked against Schedule 1: ratios, maximum group sizes and qualified '
       'staff, mixed-age groups, the reduced ratios allowed at arrival, departure and rest times, family age groups '
       'and an RECE in every group. ' + TAIL)
NEW = ('|Each room at a licensed centre is checked against Schedule 1: ratios, maximum group sizes, mixed-age '
       'groups, the reduced ratios allowed at arrival, departure and rest times, family age groups, and the share '
       'of RECEs each group needs. ' + TAIL)
T = {
    'fr': 'Chaque salle d’un centre agréé est vérifiée selon l’annexe 1 : ratios, taille maximale des groupes, '
          'groupes d’âges mixtes, ratios réduits permis à l’arrivée, au départ et pendant le repos, groupes d’âge '
          'familial et proportion d’EPEI requise dans chaque groupe. Les directions et les éducatrices voient le même '
          'résultat en direct.',
    'es': 'Cada sala de un centro con licencia se comprueba según el Anexo 1: proporciones, tamaño máximo de grupo, '
          'grupos de edades mixtas, las proporciones reducidas permitidas a la llegada, la salida y el descanso, grupos '
          'de edad familiar y la proporción de RECE que necesita cada grupo. Directores y educadores ven el mismo '
          'resultado en vivo.',
    'hi': 'लाइसेंसशुदा केंद्र के हर कमरे की जाँच अनुसूची 1 के अनुसार होती है: अनुपात, अधिकतम समूह आकार, मिश्रित आयु समूह, '
          'आगमन, प्रस्थान और आराम के समय अनुमत कम अनुपात, पारिवारिक आयु समूह और हर समूह के लिए ज़रूरी RECE का अनुपात। '
          'निदेशक और शिक्षक एक ही लाइव परिणाम देखते हैं।',
}

src, dst = sys.argv[1], sys.argv[2]
for lang, text in sorted(T.items()):
    d = json.load(io.open(os.path.join(src, lang + '.json'), encoding='utf-8'))
    if OLD not in d:
        sys.exit('%s: old key not found' % lang)
    del d[OLD]
    d[NEW] = text
    with io.open(os.path.join(dst, lang + '.json'), 'w', encoding='utf-8') as f:
        f.write(json.dumps(d, ensure_ascii=False, separators=(',', ':')))
    print(lang, len(d))
