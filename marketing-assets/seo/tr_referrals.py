# -*- coding: utf-8 -*-
"""FR (Canadian) / ES / HI for the referral program (referral_edits.py, 2026-10-13), by hand.
Amounts: "200 $" in French (Canadian usage), "$200" in Spanish and Hindi."""

T = {
'🎁 Referral Program': ('🎁 Programme de recommandation', '🎁 Programa de referidos', '🎁 रेफ़रल प्रोग्राम'),
'{0}Referral Program{/0}': ('{0}Programme de recommandation{/0}', '{0}Programa de referidos{/0}', '{0}रेफ़रल प्रोग्राम{/0}'),
'{0}{1}$200{/1}{2}CAD per program{/2}{/0}': (
    '{0}{1}200 ${/1}{2}CA par service{/2}{/0}',
    '{0}{1}$200{/1}{2}CAD por programa{/2}{/0}',
    '{0}{1}$200{/1}{2}CAD प्रति प्रोग्राम{/2}{/0}'),
'🎁 Referral program': ('🎁 Programme de recommandation', '🎁 Programa de referidos', '🎁 रेफ़रल प्रोग्राम'),
'Give a month. {0}Get $200.{/0}': (
    'Offrez un mois. {0}Recevez 200 $.{/0}',
    'Regala un mes. {0}Recibe $200.{/0}',
    'एक महीना दें। {0}$200 पाएँ।{/0}'),
'Know a childcare program that would love KiddieTrac? They get their first month free, and you get $200 CAD once they’ve been a paying customer for 60 days. Anyone can refer.': (
    'Vous connaissez un service de garde qui adorerait KiddieTrac? Il obtient son premier mois gratuit, et vous recevez 200 $ CA une fois qu’il est client payant depuis 60 jours. Tout le monde peut recommander.',
    '¿Conoces un programa de cuidado infantil al que le encantaría KiddieTrac? Recibe su primer mes gratis, y tú recibes $200 CAD cuando lleve 60 días como cliente de pago. Cualquiera puede recomendar.',
    'क्या आप किसी ऐसे चाइल्डकेयर प्रोग्राम को जानते हैं जिसे KiddieTrac पसंद आएगा? उन्हें पहला महीना मुफ़्त मिलता है, और 60 दिन तक भुगतान करने वाला ग्राहक रहने के बाद आपको $200 CAD मिलते हैं। कोई भी रेफ़र कर सकता है।'),
'Refer a program →': ('Recommander un service →', 'Recomendar un programa →', 'प्रोग्राम रेफ़र करें →'),
'{0}Refer a program →{/0}': ('{0}Recommander un service →{/0}', '{0}Recomendar un programa →{/0}', '{0}प्रोग्राम रेफ़र करें →{/0}'),
'🎁 KiddieTrac referral program': ('🎁 Programme de recommandation KiddieTrac', '🎁 Programa de referidos de KiddieTrac', '🎁 KiddieTrac रेफ़रल प्रोग्राम'),
'Know a childcare program that would love KiddieTrac? Introduce us. They get their first month free, and once they’ve been a paying customer for 60 days, we send you $200 CAD by e-transfer.': (
    'Vous connaissez un service de garde qui adorerait KiddieTrac? Présentez-nous. Il obtient son premier mois gratuit et, une fois qu’il est client payant depuis 60 jours, nous vous envoyons 200 $ CA par virement électronique.',
    '¿Conoces un programa de cuidado infantil al que le encantaría KiddieTrac? Preséntanos. Recibe su primer mes gratis y, cuando lleve 60 días como cliente de pago, te enviamos $200 CAD por transferencia electrónica.',
    'क्या आप किसी ऐसे चाइल्डकेयर प्रोग्राम को जानते हैं जिसे KiddieTrac पसंद आएगा? हमारा परिचय करवाइए। उन्हें पहला महीना मुफ़्त मिलता है, और 60 दिन तक भुगतान करने वाला ग्राहक रहने के बाद हम आपको ई-ट्रांसफ़र से $200 CAD भेजते हैं।'),
'How it works': ('Comment ça marche', 'Cómo funciona', 'यह कैसे काम करता है'),
'💸 $200 CAD per program': ('💸 200 $ CA par service', '💸 $200 CAD por programa', '💸 हर प्रोग्राम पर $200 CAD'),
'🎁 Their first month free': ('🎁 Leur premier mois gratuit', '🎁 Su primer mes gratis', '🎁 उनका पहला महीना मुफ़्त'),
'🙌 Anyone can refer': ('🙌 Tout le monde peut recommander', '🙌 Cualquiera puede recomendar', '🙌 कोई भी रेफ़र कर सकता है'),
'Three women talking over coffee at a table': (
    'Trois femmes qui discutent autour d’un café',
    'Tres mujeres conversando con un café en una mesa',
    'मेज़ पर कॉफ़ी के साथ बातें करती तीन महिलाएँ'),
'{0}🎁{/0}First month free': ('{0}🎁{/0}Premier mois gratuit', '{0}🎁{/0}Primer mes gratis', '{0}🎁{/0}पहला महीना मुफ़्त'),
'Three steps, one thank-you': ('Trois étapes, un gros merci', 'Tres pasos, un agradecimiento', 'तीन कदम, एक धन्यवाद'),
'You make the introduction. We do the rest, and we say thanks when it works out.': (
    'Vous faites les présentations. On s’occupe du reste, et on vous remercie quand ça fonctionne.',
    'Tú haces la presentación. Nosotros hacemos el resto, y te damos las gracias cuando sale bien.',
    'आप परिचय करवाइए। बाकी हम करेंगे, और बात बनने पर आपको धन्यवाद कहेंगे।'),
'Refer a program': ('Recommander un service', 'Recomendar un programa', 'प्रोग्राम रेफ़र करें'),
'Fill in the form below with the program’s details and how to reach them. We’ll take it from there.': (
    'Remplissez le formulaire ci-dessous avec les coordonnées du service de garde. On s’occupe du reste.',
    'Completa el formulario de abajo con los datos del programa y cómo contactarlo. Nosotros nos encargamos del resto.',
    'नीचे दिए फ़ॉर्म में प्रोग्राम की जानकारी और उनसे संपर्क का तरीका भरें। आगे का काम हम संभाल लेंगे।'),
'They get their first month free': ('Ils obtiennent leur premier mois gratuit', 'Reciben su primer mes gratis', 'उन्हें पहला महीना मुफ़्त मिलता है'),
'When they become a KiddieTrac customer, their first monthly subscription fee is on us.': (
    'Quand ils deviennent clients de KiddieTrac, leur premier abonnement mensuel est à nos frais.',
    'Cuando se hacen clientes de KiddieTrac, su primera cuota mensual de suscripción corre por nuestra cuenta.',
    'जब वे KiddieTrac के ग्राहक बनते हैं, तो उनकी पहली मासिक सदस्यता फ़ीस हमारी तरफ़ से।'),
'You get $200': ('Vous recevez 200 $', 'Tú recibes $200', 'आपको $200 मिलते हैं'),
'Once they’ve been a paying customer for 60 days, we send you $200 CAD by e-transfer.': (
    'Une fois qu’ils sont clients payants depuis 60 jours, nous vous envoyons 200 $ CA par virement électronique.',
    'Cuando lleven 60 días como clientes de pago, te enviamos $200 CAD por transferencia electrónica.',
    '60 दिन तक भुगतान करने वाला ग्राहक रहने के बाद, हम आपको ई-ट्रांसफ़र से $200 CAD भेजते हैं।'),
'A childcare provider building with blocks alongside a young girl': (
    'Une responsable de service de garde qui joue aux blocs avec une fillette',
    'Una cuidadora construyendo con bloques junto a una niña',
    'एक छोटी बच्ची के साथ ब्लॉक से खेलती एक चाइल्डकेयर प्रोवाइडर'),
'Who can refer': ('Qui peut recommander', 'Quién puede recomendar', 'कौन रेफ़र कर सकता है'),
'Anyone who knows a great program': (
    'Toute personne qui connaît un bon service de garde',
    'Cualquiera que conozca un buen programa',
    'हर वह व्यक्ति जो किसी अच्छे प्रोग्राम को जानता है'),
'Childcare runs on word of mouth. If you know a centre, a home child care agency or a before-and-after school program that could use a hand with the paperwork, tell us.': (
    'En services de garde, tout passe par le bouche-à-oreille. Si vous connaissez un centre, une agence de garde en milieu familial ou un service de garde avant et après l’école qui aurait besoin d’un coup de main avec la paperasse, dites-le-nous.',
    'El cuidado infantil funciona de boca en boca. Si conoces un centro, una agencia de cuidado en el hogar o un programa antes y después de la escuela al que le vendría bien una mano con el papeleo, cuéntanos.',
    'चाइल्डकेयर में बात मुँह-ज़बानी फैलती है। अगर आप किसी सेंटर, होम चाइल्डकेयर एजेंसी या स्कूल से पहले-और-बाद वाले प्रोग्राम को जानते हैं जिसे कागज़ी काम में मदद चाहिए, तो हमें बताइए।'),
'🏫 KiddieTrac customers': ('🏫 Clients de KiddieTrac', '🏫 Clientes de KiddieTrac', '🏫 KiddieTrac ग्राहक'),
'🧑‍🏫 Educators': ('🧑‍🏫 Éducatrices et éducateurs', '🧑‍🏫 Educadores', '🧑‍🏫 शिक्षक'),
'🧭 Consultants': ('🧭 Consultants', '🧭 Consultores', '🧭 सलाहकार'),
'👪 Parents': ('👪 Parents', '👪 Padres', '👪 माता-पिता'),
'🤝 Anyone else': ('🤝 Et tout le monde', '🤝 Cualquier otra persona', '🤝 कोई भी और'),
'You can’t refer your own agency or centre. The program must be new to KiddieTrac.': (
    'Vous ne pouvez pas recommander votre propre agence ou centre. Le service de garde doit être nouveau chez KiddieTrac.',
    'No puedes recomendar tu propia agencia o centro. El programa debe ser nuevo en KiddieTrac.',
    'आप अपनी ख़ुद की एजेंसी या सेंटर को रेफ़र नहीं कर सकते। प्रोग्राम KiddieTrac के लिए नया होना चाहिए।'),
'Who should we say hello to?': ('À qui devrions-nous dire bonjour?', '¿A quién deberíamos saludar?', 'हम किससे संपर्क करें?'),
'Tell us about the program and how to reach you. We’ll reach out to them about KiddieTrac and keep you posted.': (
    'Parlez-nous du service de garde et dites-nous comment vous joindre. Nous communiquerons avec eux au sujet de KiddieTrac et vous tiendrons au courant.',
    'Cuéntanos sobre el programa y cómo contactarte. Nos pondremos en contacto con ellos sobre KiddieTrac y te mantendremos al tanto.',
    'प्रोग्राम के बारे में और आपसे संपर्क का तरीका बताइए। हम उनसे KiddieTrac के बारे में संपर्क करेंगे और आपको जानकारी देते रहेंगे।'),
'{0}🙋{/0}About you': ('{0}🙋{/0}À propos de vous', '{0}🙋{/0}Sobre ti', '{0}🙋{/0}आपके बारे में'),
'Your email {0}*{/0}': ('Votre courriel {0}*{/0}', 'Tu correo electrónico {0}*{/0}', 'आपका ईमेल {0}*{/0}'),
'you@example.com': ('vous@exemple.com', 'tu@ejemplo.com', 'aap@example.com'),
'Your phone {0}(optional){/0}': ('Votre téléphone {0}(facultatif){/0}', 'Tu teléfono {0}(opcional){/0}', 'आपका फ़ोन {0}(वैकल्पिक){/0}'),
'You are… {0}*{/0}': ('Vous êtes… {0}*{/0}', 'Eres… {0}*{/0}', 'आप हैं… {0}*{/0}'),
'KiddieTrac customer': ('Client de KiddieTrac', 'Cliente de KiddieTrac', 'KiddieTrac ग्राहक'),
'Educator': ('Éducatrice ou éducateur', 'Educador/a', 'शिक्षक'),
'Consultant': ('Consultant', 'Consultor/a', 'सलाहकार'),
'Parent': ('Parent', 'Padre o madre', 'माता-पिता'),
'Other': ('Autre', 'Otro', 'अन्य'),
'{0}🏫{/0}The program you’re referring': ('{0}🏫{/0}Le service que vous recommandez', '{0}🏫{/0}El programa que recomiendas', '{0}🏫{/0}जिस प्रोग्राम को आप रेफ़र कर रहे हैं'),
'Program name {0}*{/0}': ('Nom du service de garde {0}*{/0}', 'Nombre del programa {0}*{/0}', 'प्रोग्राम का नाम {0}*{/0}'),
'e.g. Little Acorns Child Care': ('p. ex. Garderie Les Petits Glands', 'p. ej. Guardería Bellotitas', 'जैसे Little Acorns Child Care'),
'Contact person {0}*{/0} {1}(owner or director){/1}': (
    'Personne-ressource {0}*{/0} {1}(propriétaire ou direction){/1}',
    'Persona de contacto {0}*{/0} {1}(dueño/a o director/a){/1}',
    'संपर्क व्यक्ति {0}*{/0} {1}(मालिक या डायरेक्टर){/1}'),
'Their email': ('Leur courriel', 'Su correo electrónico', 'उनका ईमेल'),
'Their phone': ('Leur téléphone', 'Su teléfono', 'उनका फ़ोन'),
'An email or a phone number for them, or both.': (
    'Leur courriel ou leur numéro de téléphone, ou les deux.',
    'Su correo o su número de teléfono, o ambos.',
    'उनका ईमेल या फ़ोन नंबर, या दोनों।'),
'City / province': ('Ville / province', 'Ciudad / provincia', 'शहर / प्रांत'),
'e.g. Orangeville, ON': ('p. ex. Orangeville (Ont.)', 'p. ej. Orangeville, ON', 'जैसे Orangeville, ON'),
'Anything that would help? {0}(optional){/0}': ('Autre chose qui pourrait aider? {0}(facultatif){/0}', '¿Algo más que nos ayude? {0}(opcional){/0}', 'कोई और जानकारी जो मदद करे? {0}(वैकल्पिक){/0}'),
'How you know them, what they use today, the best time to reach them…': (
    'Comment vous les connaissez, ce qu’ils utilisent aujourd’hui, le meilleur moment pour les joindre…',
    'Cómo los conoces, qué usan hoy, el mejor momento para contactarlos…',
    'आप उन्हें कैसे जानते हैं, वे अभी क्या इस्तेमाल करते हैं, उनसे संपर्क का सबसे अच्छा समय…'),
'Website': ('Site Web', 'Sitio web', 'वेबसाइट'),
'I have permission to share this contact’s details with KiddieTrac. {0}*{/0}': (
    'J’ai la permission de transmettre les coordonnées de cette personne à KiddieTrac. {0}*{/0}',
    'Tengo permiso para compartir los datos de este contacto con KiddieTrac. {0}*{/0}',
    'मेरे पास इस संपर्क की जानकारी KiddieTrac के साथ साझा करने की अनुमति है। {0}*{/0}'),
'I agree to the {0}referral program terms{/0}. {1}*{/1}': (
    'J’accepte les {0}conditions du programme de recommandation{/0}. {1}*{/1}',
    'Acepto las {0}condiciones del programa de referidos{/0}. {1}*{/1}',
    'मैं {0}रेफ़रल प्रोग्राम की शर्तों{/0} से सहमत हूँ। {1}*{/1}'),
'Send my referral →': ('Envoyer ma recommandation →', 'Enviar mi recomendación →', 'मेरा रेफ़रल भेजें →'),
'We use these details only for this referral. Referring someone doesn’t sign you up for our newsletter.': (
    'Nous utilisons ces renseignements uniquement pour cette recommandation. Recommander quelqu’un ne vous abonne pas à notre infolettre.',
    'Usamos estos datos solo para esta recomendación. Recomendar a alguien no te suscribe a nuestro boletín.',
    'हम यह जानकारी केवल इस रेफ़रल के लिए इस्तेमाल करते हैं। किसी को रेफ़र करने से आप हमारे न्यूज़लेटर में शामिल नहीं होते।'),
'Thank you! 🎉': ('Merci! 🎉', '¡Gracias! 🎉', 'धन्यवाद! 🎉'),
'We’ll reach out to {0}the program{/0} and keep you posted.': (
    'Nous communiquerons avec {0}le service de garde{/0} et vous tiendrons au courant.',
    'Nos pondremos en contacto con {0}el programa{/0} y te mantendremos al tanto.',
    'हम {0}प्रोग्राम{/0} से संपर्क करेंगे और आपको जानकारी देते रहेंगे।'),
'If they become a paying customer, you’ll get $200 CAD by e-transfer once they’ve been with us for 60 days.': (
    'S’ils deviennent clients payants, vous recevrez 200 $ CA par virement électronique après 60 jours avec nous.',
    'Si se hacen clientes de pago, recibirás $200 CAD por transferencia electrónica cuando lleven 60 días con nosotros.',
    'अगर वे भुगतान करने वाले ग्राहक बनते हैं, तो हमारे साथ 60 दिन पूरे होने पर आपको ई-ट्रांसफ़र से $200 CAD मिलेंगे।'),
'Refer another program': ('Recommander un autre service', 'Recomendar otro programa', 'एक और प्रोग्राम रेफ़र करें'),
'Please fill in the fields marked with *.': (
    'Veuillez remplir les champs marqués d’un *.',
    'Completa los campos marcados con *.',
    'कृपया * वाले सभी फ़ील्ड भरें।'),
'Please tell us how you know KiddieTrac: customer, educator, consultant, parent or other.': (
    'Dites-nous comment vous connaissez KiddieTrac : client, éducatrice ou éducateur, consultant, parent ou autre.',
    'Cuéntanos cómo conoces KiddieTrac: cliente, educador/a, consultor/a, padre o madre, u otro.',
    'कृपया बताएँ कि आप KiddieTrac को कैसे जानते हैं: ग्राहक, शिक्षक, सलाहकार, माता-पिता या अन्य।'),
'Please add the program contact’s email or phone.': (
    'Veuillez ajouter le courriel ou le téléphone de la personne-ressource.',
    'Agrega el correo o el teléfono del contacto del programa.',
    'कृपया प्रोग्राम के संपर्क व्यक्ति का ईमेल या फ़ोन जोड़ें।'),
'Please check the email addresses.': ('Veuillez vérifier les adresses courriel.', 'Revisa las direcciones de correo.', 'कृपया ईमेल पते जाँच लें।'),
'That’s your own email address. Self-referrals aren’t eligible.': (
    'C’est votre propre adresse courriel. On ne peut pas se recommander soi-même.',
    'Esa es tu propia dirección de correo. Las autorrecomendaciones no son válidas.',
    'यह आपका अपना ईमेल पता है। ख़ुद को रेफ़र करना मान्य नहीं है।'),
'Please confirm you have permission to share their details, and agree to the program terms.': (
    'Veuillez confirmer que vous avez la permission de transmettre leurs coordonnées et accepter les conditions du programme.',
    'Confirma que tienes permiso para compartir sus datos y acepta las condiciones del programa.',
    'कृपया पुष्टि करें कि आपके पास उनकी जानकारी साझा करने की अनुमति है, और प्रोग्राम की शर्तों से सहमत हों।'),
'Sorry, that didn’t go through. Please try again, or email sales@kiddietrac.com.': (
    'Désolés, l’envoi n’a pas fonctionné. Veuillez réessayer ou écrire à sales@kiddietrac.com.',
    'Lo sentimos, no se pudo enviar. Inténtalo de nuevo o escribe a sales@kiddietrac.com.',
    'माफ़ कीजिए, यह नहीं भेजा जा सका। कृपया फिर से कोशिश करें, या sales@kiddietrac.com पर ईमेल करें।'),
'Questions about the referral program': (
    'Questions sur le programme de recommandation',
    'Preguntas sobre el programa de referidos',
    'रेफ़रल प्रोग्राम के बारे में सवाल'),
'How much do I earn?': ('Combien est-ce que je reçois?', '¿Cuánto gano?', 'मुझे कितना मिलता है?'),
'A one-time $200 CAD for each program you refer that becomes a paying KiddieTrac customer. Refer more than one program, and you earn $200 for each one that joins.': (
    'Un montant unique de 200 $ CA pour chaque service de garde recommandé qui devient client payant de KiddieTrac. Recommandez-en plus d’un, et vous recevez 200 $ pour chacun qui se joint à nous.',
    'Un pago único de $200 CAD por cada programa que recomiendes y que se convierta en cliente de pago de KiddieTrac. Si recomiendas más de uno, ganas $200 por cada uno que se una.',
    'हर उस प्रोग्राम के लिए एक बार $200 CAD, जिसे आप रेफ़र करें और जो KiddieTrac का भुगतान करने वाला ग्राहक बने। एक से ज़्यादा प्रोग्राम रेफ़र करें, तो जुड़ने वाले हर प्रोग्राम पर $200।'),
'When and how do I get paid?': ('Quand et comment suis-je payé?', '¿Cuándo y cómo me pagan?', 'मुझे भुगतान कब और कैसे मिलता है?'),
'By e-transfer, once the program you referred has been a paying customer for 60 days. We’ll contact you to arrange it.': (
    'Par virement électronique, une fois que le service recommandé est client payant depuis 60 jours. Nous communiquerons avec vous pour l’organiser.',
    'Por transferencia electrónica, cuando el programa que recomendaste lleve 60 días como cliente de pago. Te contactaremos para coordinarlo.',
    'ई-ट्रांसफ़र से, जब आपका रेफ़र किया प्रोग्राम 60 दिन तक भुगतान करने वाला ग्राहक रह चुका हो। इसके लिए हम आपसे संपर्क करेंगे।'),
'What does the program I refer get?': ('Qu’obtient le service que je recommande?', '¿Qué recibe el programa que recomiendo?', 'मेरे रेफ़र किए प्रोग्राम को क्या मिलता है?'),
'Their first month free: we waive their first monthly subscription fee. Add-ons, such as SMS & voice alerts, are billed as usual.': (
    'Son premier mois gratuit : nous annulons son premier abonnement mensuel. Les modules complémentaires, comme les alertes texto et vocales, sont facturés normalement.',
    'Su primer mes gratis: no cobramos su primera cuota mensual de suscripción. Los complementos, como las alertas por SMS y voz, se facturan como siempre.',
    'उनका पहला महीना मुफ़्त: हम उनकी पहली मासिक सदस्यता फ़ीस माफ़ करते हैं। SMS और वॉइस अलर्ट जैसे ऐड-ऑन का बिल हमेशा की तरह बनता है।'),
'Who can refer?': ('Qui peut recommander?', '¿Quién puede recomendar?', 'कौन रेफ़र कर सकता है?'),
'Anyone: KiddieTrac customers, educators, consultants, parents, or anyone who knows a childcare program. You can’t refer your own agency or centre.': (
    'Tout le monde : clients de KiddieTrac, éducatrices et éducateurs, consultants, parents, ou toute personne qui connaît un service de garde. Vous ne pouvez pas recommander votre propre agence ou centre.',
    'Cualquiera: clientes de KiddieTrac, educadores, consultores, padres o cualquier persona que conozca un programa de cuidado infantil. No puedes recomendar tu propia agencia o centro.',
    'कोई भी: KiddieTrac ग्राहक, शिक्षक, सलाहकार, माता-पिता, या कोई भी जो किसी चाइल्डकेयर प्रोग्राम को जानता हो। आप अपनी ख़ुद की एजेंसी या सेंटर को रेफ़र नहीं कर सकते।'),
'What if someone else already referred them?': (
    'Et si quelqu’un d’autre les a déjà recommandés?',
    '¿Y si otra persona ya los recomendó?',
    'अगर किसी और ने उन्हें पहले ही रेफ़र कर दिया हो तो?'),
'Each new program earns one reward, and the first valid referral we receive gets it. Your referral also has to reach us before the program signs up or books a demo with us another way.': (
    'Chaque nouveau service donne droit à une seule récompense, versée à la première recommandation valide reçue. Votre recommandation doit aussi nous parvenir avant que le service s’inscrive ou réserve une démo par un autre moyen.',
    'Cada programa nuevo genera una sola recompensa, para la primera recomendación válida que recibamos. Tu recomendación también debe llegarnos antes de que el programa se registre o reserve una demostración por otra vía.',
    'हर नए प्रोग्राम पर एक ही इनाम मिलता है, और यह हमें मिले पहले मान्य रेफ़रल को जाता है। आपका रेफ़रल हमें तब तक मिल जाना चाहिए जब तक प्रोग्राम किसी और तरीके से साइन अप न कर ले या डेमो बुक न कर ले।'),
'Which programs count?': ('Quels services sont admissibles?', '¿Qué programas cuentan?', 'कौन से प्रोग्राम मान्य हैं?'),
'New customers only. A program that already uses KiddieTrac, or that cancelled in the last 12 months, isn’t eligible.': (
    'Les nouveaux clients seulement. Un service qui utilise déjà KiddieTrac, ou qui a annulé au cours des 12 derniers mois, n’est pas admissible.',
    'Solo clientes nuevos. Un programa que ya usa KiddieTrac, o que canceló en los últimos 12 meses, no es elegible.',
    'केवल नए ग्राहक। जो प्रोग्राम पहले से KiddieTrac इस्तेमाल करता है, या जिसने पिछले 12 महीनों में सदस्यता रद्द की है, वह मान्य नहीं है।'),
'Referral program terms {0}Effective October 2026{/0}': (
    'Conditions du programme de recommandation {0}En vigueur en octobre 2026{/0}',
    'Condiciones del programa de referidos {0}Vigentes desde octubre de 2026{/0}',
    'रेफ़रल प्रोग्राम की शर्तें {0}अक्टूबर 2026 से लागू{/0}'),
'{0}The reward.{/0} KiddieTrac pays the person who refers a program (the referrer) a one-time referral fee of $200 CAD for each eligible program they refer that becomes a paying KiddieTrac customer.': (
    '{0}La récompense.{/0} KiddieTrac verse à la personne qui recommande un service (la personne référente) des frais de recommandation uniques de 200 $ CA pour chaque service admissible recommandé qui devient client payant de KiddieTrac.',
    '{0}La recompensa.{/0} KiddieTrac paga a quien recomienda un programa (la persona que refiere) una comisión única de $200 CAD por cada programa elegible que recomiende y que se convierta en cliente de pago de KiddieTrac.',
    '{0}इनाम।{/0} KiddieTrac किसी प्रोग्राम को रेफ़र करने वाले व्यक्ति (रेफ़रर) को हर उस मान्य प्रोग्राम के लिए एक बार $200 CAD की रेफ़रल फ़ीस देता है, जो KiddieTrac का भुगतान करने वाला ग्राहक बनता है।'),
'{0}When it’s paid.{/0} The fee is paid by e-transfer after the referred program has been a paying customer for 60 days.': (
    '{0}Quand elle est versée.{/0} Les frais sont versés par virement électronique une fois que le service recommandé est client payant depuis 60 jours.',
    '{0}Cuándo se paga.{/0} La comisión se paga por transferencia electrónica cuando el programa recomendado lleve 60 días como cliente de pago.',
    '{0}भुगतान कब होता है।{/0} रेफ़र किए गए प्रोग्राम के 60 दिन तक भुगतान करने वाला ग्राहक रहने के बाद फ़ीस ई-ट्रांसफ़र से दी जाती है।'),
'{0}The referred program’s offer.{/0} The referred program’s first month is free. This applies to its first monthly subscription fee only, not to add-ons such as SMS & voice alerts.': (
    '{0}L’offre pour le service recommandé.{/0} Le premier mois du service recommandé est gratuit. Cela s’applique uniquement à son premier abonnement mensuel, et non aux modules complémentaires comme les alertes texto et vocales.',
    '{0}La oferta para el programa recomendado.{/0} El primer mes del programa recomendado es gratis. Se aplica solo a su primera cuota mensual de suscripción, no a complementos como las alertas por SMS y voz.',
    '{0}रेफ़र किए गए प्रोग्राम के लिए ऑफ़र।{/0} रेफ़र किए गए प्रोग्राम का पहला महीना मुफ़्त है। यह केवल उसकी पहली मासिक सदस्यता फ़ीस पर लागू होता है, SMS और वॉइस अलर्ट जैसे ऐड-ऑन पर नहीं।'),
'{0}Who can refer.{/0} Anyone can take part: KiddieTrac customers, educators, consultants, parents and anyone else.': (
    '{0}Qui peut recommander.{/0} Tout le monde peut participer : clients de KiddieTrac, éducatrices et éducateurs, consultants, parents et toute autre personne.',
    '{0}Quién puede recomendar.{/0} Cualquiera puede participar: clientes de KiddieTrac, educadores, consultores, padres y cualquier otra persona.',
    '{0}कौन रेफ़र कर सकता है।{/0} कोई भी भाग ले सकता है: KiddieTrac ग्राहक, शिक्षक, सलाहकार, माता-पिता और कोई भी अन्य व्यक्ति।'),
'{0}Refer first.{/0} The referral must reach us through this page before the referred program signs up for KiddieTrac or books a demo with us through another channel.': (
    '{0}Recommandez d’abord.{/0} La recommandation doit nous parvenir par cette page avant que le service recommandé s’inscrive à KiddieTrac ou réserve une démo par un autre moyen.',
    '{0}Primero la recomendación.{/0} La recomendación debe llegarnos a través de esta página antes de que el programa recomendado se registre en KiddieTrac o reserve una demostración por otra vía.',
    '{0}पहले रेफ़र करें।{/0} रेफ़रल हमें इसी पेज से तब तक मिल जाना चाहिए जब तक रेफ़र किया गया प्रोग्राम KiddieTrac के लिए साइन अप न कर ले या किसी और तरीके से डेमो बुक न कर ले।'),
'{0}New customers only.{/0} The referred program can’t be a current KiddieTrac customer, or a customer that cancelled within the last 12 months.': (
    '{0}Nouveaux clients seulement.{/0} Le service recommandé ne peut pas être un client actuel de KiddieTrac, ni un client ayant annulé au cours des 12 derniers mois.',
    '{0}Solo clientes nuevos.{/0} El programa recomendado no puede ser un cliente actual de KiddieTrac, ni un cliente que canceló en los últimos 12 meses.',
    '{0}केवल नए ग्राहक।{/0} रेफ़र किया गया प्रोग्राम KiddieTrac का मौजूदा ग्राहक नहीं हो सकता, न ही ऐसा ग्राहक जिसने पिछले 12 महीनों में सदस्यता रद्द की हो।'),
'{0}One reward per program.{/0} Each new program earns one reward. If more than one person refers the same program, the first valid referral we receive gets it.': (
    '{0}Une récompense par service.{/0} Chaque nouveau service donne droit à une seule récompense. Si plus d’une personne recommande le même service, la première recommandation valide reçue l’obtient.',
    '{0}Una recompensa por programa.{/0} Cada programa nuevo genera una sola recompensa. Si más de una persona recomienda el mismo programa, la recibe la primera recomendación válida que llegue.',
    '{0}हर प्रोग्राम पर एक इनाम।{/0} हर नए प्रोग्राम पर एक ही इनाम मिलता है। अगर एक से ज़्यादा लोग एक ही प्रोग्राम को रेफ़र करें, तो इनाम हमें मिले पहले मान्य रेफ़रल को जाता है।'),
'{0}No self-referrals.{/0} You can’t refer your own agency or centre.': (
    '{0}Pas d’autorecommandation.{/0} Vous ne pouvez pas recommander votre propre agence ou centre.',
    '{0}Sin autorrecomendaciones.{/0} No puedes recomendar tu propia agencia o centro.',
    '{0}ख़ुद को रेफ़र नहीं।{/0} आप अपनी ख़ुद की एजेंसी या सेंटर को रेफ़र नहीं कर सकते।'),
'{0}Permission.{/0} By sending a referral, you confirm you have permission to share the contact’s details with KiddieTrac.': (
    '{0}Permission.{/0} En envoyant une recommandation, vous confirmez avoir la permission de transmettre les coordonnées de la personne à KiddieTrac.',
    '{0}Permiso.{/0} Al enviar una recomendación, confirmas que tienes permiso para compartir los datos del contacto con KiddieTrac.',
    '{0}अनुमति।{/0} रेफ़रल भेजकर आप पुष्टि करते हैं कि आपके पास संपर्क की जानकारी KiddieTrac के साथ साझा करने की अनुमति है।'),
'{0}Changes.{/0} KiddieTrac may change or end the program at any time. Referrals sent before a change keep the terms that applied when they were sent.': (
    '{0}Modifications.{/0} KiddieTrac peut modifier ou mettre fin au programme en tout temps. Les recommandations envoyées avant une modification conservent les conditions en vigueur au moment de leur envoi.',
    '{0}Cambios.{/0} KiddieTrac puede cambiar o terminar el programa en cualquier momento. Las recomendaciones enviadas antes de un cambio conservan las condiciones vigentes cuando se enviaron.',
    '{0}बदलाव।{/0} KiddieTrac किसी भी समय प्रोग्राम बदल या बंद कर सकता है। किसी बदलाव से पहले भेजे गए रेफ़रल पर वही शर्तें लागू रहती हैं जो भेजते समय थीं।'),
'{0}Taxes.{/0} Rewards may be taxable income. The referrer is responsible for reporting them.': (
    '{0}Impôts.{/0} Les récompenses peuvent constituer un revenu imposable. La personne référente est responsable de les déclarer.',
    '{0}Impuestos.{/0} Las recompensas pueden ser ingresos sujetos a impuestos. La persona que refiere es responsable de declararlas.',
    '{0}कर।{/0} इनाम कर-योग्य आय हो सकते हैं। इन्हें घोषित करने की ज़िम्मेदारी रेफ़रर की है।'),
'{0}Where it applies.{/0} Void where prohibited.': (
    '{0}Application.{/0} Nul là où la loi l’interdit.',
    '{0}Dónde aplica.{/0} Nulo donde esté prohibido.',
    '{0}कहाँ लागू है।{/0} जहाँ निषिद्ध है, वहाँ अमान्य।'),
'{0}Questions?{/0} {1}Contact us{/1} and we’ll be happy to help.': (
    '{0}Des questions?{/0} {1}Écrivez-nous{/1}, nous serons heureux de vous aider.',
    '{0}¿Preguntas?{/0} {1}Contáctanos{/1} y con gusto te ayudaremos.',
    '{0}सवाल?{/0} {1}हमसे संपर्क करें{/1}, हमें मदद करके ख़ुशी होगी।'),
'Know a program that deserves {0}its evenings back?{/0}': (
    'Vous connaissez un service qui mérite {0}de retrouver ses soirées?{/0}',
    '¿Conoces un programa que merece {0}recuperar sus tardes?{/0}',
    'क्या आप ऐसे प्रोग्राम को जानते हैं जिसे {0}अपनी शामें वापस मिलनी चाहिए?{/0}'),
'Give them a month on us. We’ll say thanks with $200.': (
    'Offrez-leur un mois à nos frais. On vous remercie avec 200 $.',
    'Regálales un mes por nuestra cuenta. Te lo agradeceremos con $200.',
    'उन्हें एक महीना हमारी तरफ़ से दीजिए। हम $200 से आपका शुक्रिया करेंगे।'),
}
