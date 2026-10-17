# -*- coding: utf-8 -*-
"""fr (Canadian) / es / hi for the shop tablets (shop_tablets_edits.py, 2026-10-16), by hand.
Keys follow the shop's existing runtime keys: '|text', 'A|<name> with the KiddieTrac logo',
'b|Options: <opts>' with {0}…{/0} around the bold label.

usage: python tr_shop_tablets.py <i18n dir in> <i18n dir out>"""
import io
import json
import os
import sys

S = 'Android tablets for check-in at the door, in the classroom and in the office. KiddieTrac runs in the tablet’s browser. Ask us about setting them up for your centre.'
A = 'Budget-friendly tablets for a check-in station or a classroom, from the Fire 7 to the Fire Max 11. KiddieTrac runs in the tablet’s browser.'
I = 'iPad mini, iPad and iPad Air for directors, educators and the front desk. KiddieTrac runs in Safari and can be added to the Home Screen like an app.'

T = {
    '|Tablets': ('Tablettes', 'Tabletas', 'टैबलेट'),
    '|Samsung Galaxy Tab': ('Samsung Galaxy Tab', 'Samsung Galaxy Tab', 'Samsung Galaxy Tab'),
    '|Amazon Fire Tablet': ('Tablette Amazon Fire', 'Tableta Amazon Fire', 'Amazon Fire टैबलेट'),
    '|Apple iPad': ('Apple iPad', 'Apple iPad', 'Apple iPad'),
    'A|Samsung Galaxy Tab with the KiddieTrac logo': ('Samsung Galaxy Tab affichant KiddieTrac', 'Samsung Galaxy Tab con KiddieTrac en pantalla', 'KiddieTrac दिखाता Samsung Galaxy Tab'),
    'A|Amazon Fire Tablet with the KiddieTrac logo': ('Tablette Amazon Fire affichant KiddieTrac', 'Tableta Amazon Fire con KiddieTrac en pantalla', 'KiddieTrac दिखाता Amazon Fire टैबलेट'),
    'A|Apple iPad with the KiddieTrac logo': ('Apple iPad affichant KiddieTrac', 'Apple iPad con KiddieTrac en pantalla', 'KiddieTrac दिखाता Apple iPad'),
    '|' + S: ('Des tablettes Android pour l’arrivée à la porte, en salle et au bureau. KiddieTrac fonctionne dans le navigateur de la tablette. Demandez-nous de les configurer pour votre centre.',
              'Tabletas Android para el registro en la puerta, en el aula y en la oficina. KiddieTrac funciona en el navegador de la tableta. Pregúntenos por configurarlas para su centro.',
              'दरवाज़े पर चेक-इन, कक्षा और ऑफ़िस के लिए Android टैबलेट। KiddieTrac टैबलेट के ब्राउज़र में चलता है। अपने सेंटर के लिए इन्हें सेट अप करवाने के बारे में हमसे पूछें।'),
    '|' + A: ('Des tablettes abordables pour un poste d’arrivée ou une salle, de la Fire 7 à la Fire Max 11. KiddieTrac fonctionne dans le navigateur de la tablette.',
              'Tabletas económicas para una estación de registro o un aula, desde la Fire 7 hasta la Fire Max 11. KiddieTrac funciona en el navegador de la tableta.',
              'चेक-इन स्टेशन या कक्षा के लिए किफ़ायती टैबलेट, Fire 7 से Fire Max 11 तक। KiddieTrac टैबलेट के ब्राउज़र में चलता है।'),
    '|' + I: ('iPad mini, iPad et iPad Air pour la direction, les éducatrices et la réception. KiddieTrac fonctionne dans Safari et peut être ajouté à l’écran d’accueil comme une application.',
              'iPad mini, iPad y iPad Air para la dirección, los educadores y la recepción. KiddieTrac funciona en Safari y se puede agregar a la pantalla de inicio como una app.',
              'डायरेक्टर, शिक्षकों और फ़्रंट डेस्क के लिए iPad mini, iPad और iPad Air। KiddieTrac Safari में चलता है और ऐप की तरह होम स्क्रीन पर जोड़ा जा सकता है।'),
    'b|Options: 8.7″, 11″ or 12.4″ screen': ('{0}Options :{/0} écran de 8,7 po, 11 po ou 12,4 po', '{0}Opciones:{/0} pantalla de 8.7″, 11″ o 12.4″', '{0}विकल्प:{/0} 8.7″, 11″ या 12.4″ स्क्रीन'),
    'b|Options: 7″, 8″, 10.1″ or 11″ screen': ('{0}Options :{/0} écran de 7 po, 8 po, 10,1 po ou 11 po', '{0}Opciones:{/0} pantalla de 7″, 8″, 10.1″ u 11″', '{0}विकल्प:{/0} 7″, 8″, 10.1″ या 11″ स्क्रीन'),
    'b|Options: 8.3″ (iPad mini), 11″ or 13″ screen': ('{0}Options :{/0} écran de 8,3 po (iPad mini), 11 po ou 13 po', '{0}Opciones:{/0} pantalla de 8.3″ (iPad mini), 11″ o 13″', '{0}विकल्प:{/0} 8.3″ (iPad mini), 11″ या 13″ स्क्रीन'),
}

src, dst = sys.argv[1], sys.argv[2]
for li, lang in enumerate(('fr', 'es', 'hi')):
    d = json.load(io.open(os.path.join(src, lang + '.json'), encoding='utf-8'))
    for k, v in T.items():
        d[k] = v[li]
    with io.open(os.path.join(dst, lang + '.json'), 'w', encoding='utf-8') as f:
        f.write(json.dumps(d, ensure_ascii=False, separators=(',', ':')))
    print(lang, len(d))
