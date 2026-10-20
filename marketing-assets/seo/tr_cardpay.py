# -*- coding: utf-8 -*-
"""fr (Canadian) / es / hi for the Wallet card-payments section (cardpay_edits.py, 2026-10-20),
by hand. Keys are the shapes i18n_collect reported ('i|', 'span|', '|', 'b|'), with the
{0}…{/0} placeholders where the inline markup sits.

usage: python tr_cardpay.py <i18n dir in> <i18n dir out>"""
import io
import json
import os
import sys

P1 = "Security that's certified, not just promised. Payments run through our PCI DSS–compliant payment partner, so card details are encrypted and handled on their certified systems."
P2 = 'No card numbers on file. KiddieTrac keeps only a secure token and the last four digits, never a full card number.'
P3 = 'Paid means reconciled. Every Wallet payment is matched to its invoice automatically, so there is nothing to chase and nothing to key in.'
LEAD = 'Families will be able to pay invoices by Visa, Mastercard or Interac Debit, straight from the Wallet in the KiddieTrac parent app. Save a card once, pay in seconds, and watch the invoice settle on its own.'
TM = 'Visa, Mastercard and Interac are trademarks of their respective owners.'

T = {
    'i|Coming soon': (
        '{0}{/0}Bientôt',
        '{0}{/0}Muy pronto',
        '{0}{/0}जल्द आ रहा है',
    ),
    'span|Tap, pay, done — right from the Wallet': (
        'Touchez, payez, c’est fait — {0}directement depuis le portefeuille{/0}',
        'Toca, paga y listo — {0}directamente desde la Cartera{/0}',
        'टैप करें, भुगतान करें, हो गया — {0}सीधे वॉलेट से{/0}',
    ),
    '|' + LEAD: (
        'Les familles pourront payer leurs factures par Visa, Mastercard ou Interac Débit, directement depuis le portefeuille de l’application parents KiddieTrac. Enregistrez une carte une fois, payez en quelques secondes et la facture se règle d’elle-même.',
        'Las familias podrán pagar sus facturas con Visa, Mastercard o Interac Débito, directamente desde la Cartera de la app para padres de KiddieTrac. Guarden una tarjeta una vez, paguen en segundos y la factura se salda sola.',
        'परिवार KiddieTrac पैरेंट ऐप के वॉलेट से सीधे Visa, Mastercard या Interac Debit द्वारा इनवॉइस का भुगतान कर सकेंगे। एक बार कार्ड सेव करें, सेकंडों में भुगतान करें, और इनवॉइस अपने आप चुकता हो जाता है।',
    ),
    'b|' + P1: (
        '{0}Une sécurité certifiée, pas seulement promise.{/0} Les paiements passent par notre partenaire de paiement conforme à la norme PCI DSS : les données de carte sont chiffrées et traitées sur ses systèmes certifiés.',
        '{0}Seguridad certificada, no solo prometida.{/0} Los pagos se procesan a través de nuestro socio de pagos que cumple con PCI DSS, por lo que los datos de la tarjeta se cifran y se gestionan en sus sistemas certificados.',
        '{0}सुरक्षा जो प्रमाणित है, सिर्फ़ वादा नहीं।{/0} भुगतान हमारे PCI DSS–अनुपालक भुगतान पार्टनर के ज़रिए होते हैं, इसलिए कार्ड का विवरण एन्क्रिप्ट होकर उनके प्रमाणित सिस्टम पर संभाला जाता है।',
    ),
    'b|' + P2: (
        '{0}Aucun numéro de carte conservé.{/0} KiddieTrac ne garde qu’un jeton sécurisé et les quatre derniers chiffres, jamais le numéro de carte complet.',
        '{0}Sin números de tarjeta archivados.{/0} KiddieTrac solo guarda un token seguro y los últimos cuatro dígitos, nunca el número completo de la tarjeta.',
        '{0}कोई कार्ड नंबर सहेजा नहीं जाता।{/0} KiddieTrac केवल एक सुरक्षित टोकन और आख़िरी चार अंक रखता है, पूरा कार्ड नंबर कभी नहीं।',
    ),
    'b|' + P3: (
        '{0}Payé, c’est rapproché.{/0} Chaque paiement par portefeuille est associé automatiquement à sa facture : rien à relancer, rien à saisir.',
        '{0}Pagado significa conciliado.{/0} Cada pago desde la Cartera se vincula automáticamente con su factura, así que no hay nada que perseguir ni nada que capturar a mano.',
        '{0}भुगतान हुआ, मतलब मिलान हो गया।{/0} हर वॉलेट भुगतान अपने इनवॉइस से अपने आप मिलाया जाता है, इसलिए न कुछ पीछा करना है, न कुछ हाथ से दर्ज करना।',
    ),
    '|' + TM: (
        'Visa, Mastercard et Interac sont des marques de commerce de leurs propriétaires respectifs.',
        'Visa, Mastercard e Interac son marcas comerciales de sus respectivos propietarios.',
        'Visa, Mastercard और Interac अपने-अपने मालिकों के ट्रेडमार्क हैं।',
    ),
}

src, dst = sys.argv[1], sys.argv[2]
for li, lang in enumerate(('fr', 'es', 'hi')):
    d = json.load(io.open(os.path.join(src, lang + '.json'), encoding='utf-8'))
    for k, v in T.items():
        d[k] = v[li]
    with io.open(os.path.join(dst, lang + '.json'), 'w', encoding='utf-8') as f:
        f.write(json.dumps(d, ensure_ascii=False, separators=(',', ':')))
    print(lang, len(d))
