# -*- coding: utf-8 -*-
"""fr/es/hi for the reworded October 2026 changelog sentence (blog_oct2026_edits.py).
The dictionaries are keyed by the English text, so the old key goes and the new one comes in.

usage: python tr_blog_oct2026.py <i18n dir in> <i18n dir out>   (fr.json, es.json, hi.json)
"""
import io
import json
import os
import sys

LINK = 'See what’s built for Ontario'
OLD = ('a|For Ontario centres and agencies: live ratio checks against O. Reg. 137/15, name-to-face '
       'headcounts with an acting supervisor, sleep room checks, missing-child drills and a hazard '
       'register for the January 2027 changes, policy sign-offs with a training matrix, OCCMS fee '
       'subsidy reporting and ECE workforce funding in payroll. ' + LINK)
NEW = ('a|For Ontario centres and agencies: live ratio checks against O. Reg. 137/15, sleep room checks, '
       'OCCMS fee subsidy reporting and ECE workforce funding in payroll, plus tools for the January '
       '2027 changes: name-to-face headcounts with an acting supervisor, missing-child drills, a hazard '
       'register and policy sign-offs with a training matrix. ' + LINK)
T = {
    'fr': 'Pour les centres et agences de l’Ontario : vérification des ratios en direct selon le Règl. de l’Ont. 137/15, '
          'vérifications de la salle de sieste, rapports de subvention OCCMS et financement de la main-d’œuvre EPE dans la paie, '
          'ainsi que des outils pour les changements de janvier 2027 : comptes d’enfants nom-visage avec superviseur intérimaire, '
          'exercices d’enfant disparu, registre des dangers et signature des politiques avec matrice de formation. '
          '{0}Voir ce qui est conçu pour l’Ontario{/0}',
    'es': 'Para centros y agencias de Ontario: control de proporciones en vivo según O. Reg. 137/15, controles de la sala de siesta, '
          'informes de subsidios en OCCMS y financiamiento del personal ECE en la nómina, además de herramientas para los cambios '
          'de enero de 2027: conteos nombre a cara con supervisor interino, simulacros de niño desaparecido, registro de peligros '
          'y firma de políticas con matriz de capacitación. {0}Vea lo que hicimos para Ontario{/0}',
    'hi': 'ओंटारियो के केंद्रों और एजेंसियों के लिए: O. Reg. 137/15 के अनुसार लाइव अनुपात जाँच, नींद कक्ष की जाँच, OCCMS फ़ीस सब्सिडी '
          'रिपोर्टिंग और पेरोल में ECE कार्यबल फंडिंग, साथ ही जनवरी 2027 के बदलावों के लिए टूल: कार्यवाहक पर्यवेक्षक के साथ नाम से चेहरा '
          'मिलाकर गिनती, लापता बच्चे के अभ्यास, खतरा रजिस्टर और प्रशिक्षण मैट्रिक्स के साथ नीतियों पर हस्ताक्षर। '
          '{0}देखें ओंटारियो के लिए क्या बना है{/0}',
}

src, dst = sys.argv[1], sys.argv[2]
for lang, text in sorted(T.items()):
    d = json.load(io.open(os.path.join(src, lang + '.json'), encoding='utf-8'))
    if OLD in d:
        del d[OLD]
    d[NEW] = text
    with io.open(os.path.join(dst, lang + '.json'), 'w', encoding='utf-8') as f:
        f.write(json.dumps(d, ensure_ascii=False, separators=(',', ':')))   # the files' own format
    print(lang, len(d))
