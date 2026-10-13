# -*- coding: utf-8 -*-
"""FR (Canadian) / ES / HI for the Safe Arrival additions (2026-10-13), written by hand
(the auto-translate API has no credits). Keyed by the English TEMPLATE the collector prints;
merge_i18n.py maps each template to its dictionary key. "Safe Arrival" stays the product
name in every language, like the lockup."""

T = {
'🛡️ Safe Arrival': ('🛡️ Safe Arrival', '🛡️ Safe Arrival', '🛡️ Safe Arrival'),
'No child expected today {0}goes unnoticed{/0}': (
    'Aucun enfant attendu aujourd’hui {0}ne passe inaperçu{/0}',
    'Ningún niño esperado hoy {0}pasa desapercibido{/0}',
    'आज अपेक्षित कोई भी बच्चा {0}नज़र से नहीं छूटता{/0}'),
'If a child hasn’t been signed in by their usual drop-off time and nobody has reported an absence, KiddieTrac asks the parents. If they don’t answer, the room’s educators and the director are alerted, so someone calls.': (
    'Si l’arrivée d’un enfant n’a pas été enregistrée à son heure habituelle et que personne n’a signalé d’absence, KiddieTrac le demande aux parents. S’ils ne répondent pas, les éducatrices et éducateurs du local et la direction sont alertés, pour que quelqu’un appelle.',
    'Si un niño no ha sido registrado a su hora habitual de llegada y nadie ha reportado una ausencia, KiddieTrac pregunta a los padres. Si no responden, se alerta a los educadores del aula y a la dirección, para que alguien llame.',
    'अगर किसी बच्चे का उसके सामान्य ड्रॉप-ऑफ़ समय तक साइन-इन नहीं हुआ है और किसी ने अनुपस्थिति की सूचना नहीं दी है, तो KiddieTrac माता-पिता से पूछता है। अगर वे जवाब नहीं देते, तो कक्षा के शिक्षकों और डायरेक्टर को अलर्ट किया जाता है, ताकि कोई फ़ोन करे।'),
'Checks every scheduled child, every 5 minutes, and skips closed days': (
    'Vérifie chaque enfant prévu, toutes les 5 minutes, et ignore les jours de fermeture',
    'Revisa a cada niño programado, cada 5 minutos, y omite los días cerrados',
    'हर निर्धारित बच्चे की हर 5 मिनट में जाँच, और बंद दिनों को छोड़ देता है'),
'Parents can answer “Not attending today” in one tap': (
    'Les parents répondent « Absent aujourd’hui » d’un seul toucher',
    'Los padres pueden responder “No asiste hoy” con un toque',
    'माता-पिता एक टैप में “आज नहीं आएगा” बता सकते हैं'),
'A live board of who still needs a call, and a record of every check': (
    'Un tableau en direct des appels encore à faire, et un registre de chaque vérification',
    'Un tablero en vivo de a quién falta llamar, y un registro de cada verificación',
    'किसे अभी फ़ोन करना बाकी है, इसका लाइव बोर्ड, और हर जाँच का रिकॉर्ड'),
'See how Safe Arrival works →': (
    'Voir comment fonctionne Safe Arrival →',
    'Vea cómo funciona Safe Arrival →',
    'देखें Safe Arrival कैसे काम करता है →'),
'Example: a Safe Arrival morning': (
    'Exemple : une matinée avec Safe Arrival',
    'Ejemplo: una mañana con Safe Arrival',
    'उदाहरण: Safe Arrival के साथ एक सुबह'),
'Ava and Leo are expected': ('Ava et Leo sont attendus', 'Se espera a Ava y a Leo', 'Ava और Leo अपेक्षित हैं'),
'Both are scheduled today. Neither is signed in yet.': (
    'Les deux sont prévus aujourd’hui. Aucune arrivée n’est encore enregistrée.',
    'Ambos están programados hoy. Ninguno ha sido registrado todavía.',
    'दोनों आज के लिए निर्धारित हैं। अभी तक किसी का साइन-इन नहीं हुआ है।'),
'Parents asked: “Has Ava arrived?”': (
    'Question aux parents : « Ava est-elle arrivée? »',
    'Se pregunta a los padres: “¿Llegó Ava?”',
    'माता-पिता से पूछा गया: “क्या Ava पहुँच गई?”'),
'Once, by app notification, email or text, as each parent chose.': (
    'Une seule fois, par notification de l’appli, courriel ou texto, selon le choix de chaque parent.',
    'Una sola vez, por notificación de la app, correo o mensaje de texto, según eligió cada padre.',
    'केवल एक बार, ऐप नोटिफ़िकेशन, ईमेल या टेक्स्ट से, जैसा हर अभिभावक ने चुना है।'),
'Ava’s mom: not attending today, unwell': (
    'La mère d’Ava : absente aujourd’hui, malade',
    'La mamá de Ava: no asiste hoy, enferma',
    'Ava की माँ: आज नहीं आएगी, तबीयत ठीक नहीं'),
'Recorded as an absence. Ava’s check closes by itself.': (
    'Enregistré comme absence. La vérification d’Ava se ferme d’elle-même.',
    'Se registra como ausencia. La verificación de Ava se cierra sola.',
    'अनुपस्थिति के रूप में दर्ज। Ava की जाँच अपने-आप बंद हो जाती है।'),
'No answer for Leo: staff alerted': (
    'Aucune réponse pour Leo : le personnel est alerté',
    'Sin respuesta por Leo: se alerta al personal',
    'Leo के लिए कोई जवाब नहीं: स्टाफ़ को अलर्ट'),
'The room’s educators and the director get an alert with the parents’ numbers.': (
    'Les éducatrices et éducateurs du local et la direction reçoivent une alerte avec les numéros des parents.',
    'Los educadores del aula y la dirección reciben una alerta con los teléfonos de los padres.',
    'कक्षा के शिक्षकों और डायरेक्टर को माता-पिता के नंबरों के साथ अलर्ट मिलता है।'),
'Closed: “On the way, arriving 10:15”': (
    'Fermé : « En route, arrivée à 10 h 15 »',
    'Cerrado: “En camino, llega a las 10:15”',
    'बंद: “रास्ते में हैं, 10:15 तक पहुँचेंगे”'),
'Saved with the educator’s name and note.': (
    'Enregistré avec le nom et la note de l’éducatrice.',
    'Se guarda con el nombre y la nota del educador.',
    'शिक्षक के नाम और नोट के साथ सहेजा गया।'),
'Example morning with the default settings. Names are made up.': (
    'Exemple de matinée avec les réglages par défaut. Les noms sont fictifs.',
    'Mañana de ejemplo con la configuración predeterminada. Los nombres son ficticios.',
    'डिफ़ॉल्ट सेटिंग्स के साथ एक उदाहरण सुबह। नाम काल्पनिक हैं।'),
'What happens when a child doesn’t arrive?': (
    'Que se passe-t-il quand un enfant n’arrive pas?',
    '¿Qué pasa cuando un niño no llega?',
    'जब कोई बच्चा नहीं पहुँचता, तो क्या होता है?'),
'With Safe Arrival on, KiddieTrac checks every scheduled child every 5 minutes. If a child hasn’t been signed in by their usual drop-off time plus your grace period, and no absence was reported, the parents are asked once by app notification, email or text. If nobody answers, the room’s educators and the director are alerted and the Safe Arrival board shows who still needs a call. Every check is recorded. {0}How Safe Arrival works{/0}.': (
    'Quand Safe Arrival est activé, KiddieTrac vérifie chaque enfant prévu toutes les 5 minutes. Si l’arrivée d’un enfant n’a pas été enregistrée à son heure habituelle, plus votre délai de grâce, et qu’aucune absence n’a été signalée, les parents reçoivent une seule question par notification de l’appli, courriel ou texto. Si personne ne répond, les éducatrices et éducateurs du local et la direction sont alertés, et le tableau Safe Arrival indique qui doit encore être appelé. Chaque vérification est consignée. {0}Comment fonctionne Safe Arrival{/0}.',
    'Con Safe Arrival activado, KiddieTrac revisa a cada niño programado cada 5 minutos. Si un niño no ha sido registrado a su hora habitual de llegada más su periodo de gracia, y no se reportó ninguna ausencia, se pregunta una vez a los padres por notificación de la app, correo o mensaje de texto. Si nadie responde, se alerta a los educadores del aula y a la dirección, y el tablero de Safe Arrival muestra a quién falta llamar. Cada verificación queda registrada. {0}Cómo funciona Safe Arrival{/0}.',
    'Safe Arrival चालू होने पर KiddieTrac हर 5 मिनट में हर निर्धारित बच्चे की जाँच करता है। अगर किसी बच्चे का उसके सामान्य ड्रॉप-ऑफ़ समय और आपकी छूट अवधि के बाद भी साइन-इन नहीं हुआ है, और कोई अनुपस्थिति दर्ज नहीं है, तो माता-पिता से एक बार ऐप नोटिफ़िकेशन, ईमेल या टेक्स्ट से पूछा जाता है। अगर कोई जवाब नहीं देता, तो कक्षा के शिक्षकों और डायरेक्टर को अलर्ट किया जाता है और Safe Arrival बोर्ड दिखाता है कि किसे अभी फ़ोन करना बाकी है। हर जाँच दर्ज होती है। {0}Safe Arrival कैसे काम करता है{/0}।'),
'{0}Safe Arrival alerts{/0}': ('{0}Alertes Safe Arrival{/0}', '{0}Alertas de Safe Arrival{/0}', '{0}Safe Arrival अलर्ट{/0}'),
'Every child expected today, {0}accounted for{/0}': (
    'Chaque enfant attendu aujourd’hui, {0}bien repéré{/0}',
    'Cada niño esperado hoy, {0}bajo control{/0}',
    'आज अपेक्षित हर बच्चा, {0}हिसाब में{/0}'),
'Safe Arrival watches for children who were scheduled today but haven’t been signed in. It asks the parents first, alerts your team if nobody answers, and keeps a record of how every check was closed.': (
    'Safe Arrival repère les enfants prévus aujourd’hui dont l’arrivée n’a pas été enregistrée. Il interroge d’abord les parents, alerte votre équipe si personne ne répond, et garde une trace de la façon dont chaque vérification a été fermée.',
    'Safe Arrival vigila a los niños programados hoy que no han sido registrados. Primero pregunta a los padres, alerta a su equipo si nadie responde y guarda un registro de cómo se cerró cada verificación.',
    'Safe Arrival उन बच्चों पर नज़र रखता है जो आज के लिए निर्धारित थे लेकिन जिनका साइन-इन नहीं हुआ। यह पहले माता-पिता से पूछता है, कोई जवाब न दे तो आपकी टीम को अलर्ट करता है, और रिकॉर्ड रखता है कि हर जाँच कैसे बंद हुई।'),
'See it step by step': ('Voir étape par étape', 'Véalo paso a paso', 'चरण-दर-चरण देखें'),
'⏱️ Checks every 5 minutes': ('⏱️ Vérifie toutes les 5 minutes', '⏱️ Revisa cada 5 minutos', '⏱️ हर 5 मिनट में जाँच'),
'👪 Parents asked first': ('👪 Les parents d’abord', '👪 Primero los padres', '👪 पहले माता-पिता से पूछा जाता है'),
'🔔 Staff alerted if no answer': ('🔔 Personnel alerté sans réponse', '🔔 Alerta al personal si no hay respuesta', '🔔 जवाब न मिले तो स्टाफ़ को अलर्ट'),
'🧾 Every check recorded': ('🧾 Chaque vérification consignée', '🧾 Cada verificación registrada', '🧾 हर जाँच दर्ज'),
'⏱️ One morning, step by step': ('⏱️ Une matinée, étape par étape', '⏱️ Una mañana, paso a paso', '⏱️ एक सुबह, चरण-दर-चरण'),
'What happens when a child {0}hasn’t arrived{/0}': (
    'Ce qui se passe quand un enfant {0}n’est pas arrivé{/0}',
    'Qué pasa cuando un niño {0}no ha llegado{/0}',
    'जब कोई बच्चा {0}नहीं पहुँचा हो{/0}, तब क्या होता है'),
'Times below use the default settings: parents are asked 15 minutes after the expected time, and staff are alerted 20 minutes after that.': (
    'Les heures ci-dessous suivent les réglages par défaut : les parents sont interrogés 15 minutes après l’heure prévue, et le personnel est alerté 20 minutes plus tard.',
    'Las horas siguientes usan la configuración predeterminada: se pregunta a los padres 15 minutos después de la hora esperada, y se alerta al personal 20 minutos después.',
    'नीचे के समय डिफ़ॉल्ट सेटिंग्स पर हैं: अपेक्षित समय के 15 मिनट बाद माता-पिता से पूछा जाता है, और उसके 20 मिनट बाद स्टाफ़ को अलर्ट किया जाता है।'),
'Every scheduled child is expected': ('Chaque enfant prévu est attendu', 'Se espera a cada niño programado', 'हर निर्धारित बच्चा अपेक्षित है'),
'Each child has their usual drop-off time on their record; children without one use your default time. Only children scheduled today, at a centre that is open today, are checked.': (
    'Chaque enfant a son heure d’arrivée habituelle à son dossier; les autres suivent votre heure par défaut. Seuls les enfants prévus aujourd’hui, dans une garderie ouverte aujourd’hui, sont vérifiés.',
    'Cada niño tiene su hora habitual de llegada en su expediente; los que no la tienen usan su hora predeterminada. Solo se revisa a los niños programados hoy, en un centro que abre hoy.',
    'हर बच्चे के रिकॉर्ड में उसका सामान्य ड्रॉप-ऑफ़ समय होता है; जिनका नहीं है, उनके लिए आपका डिफ़ॉल्ट समय लागू होता है। केवल आज के लिए निर्धारित बच्चों की, और आज खुले सेंटर में ही, जाँच होती है।'),
'Not signed in? The parents are asked once': (
    'Arrivée non enregistrée? Les parents sont interrogés une fois',
    '¿No registrado? Se pregunta una vez a los padres',
    'साइन-इन नहीं हुआ? माता-पिता से एक बार पूछा जाता है'),
'After your grace period, the parents get “Has Ava arrived?” through the channels each parent chose: app notification, email or text. If she’s on the way, there’s no need to reply.': (
    'Après votre délai de grâce, les parents reçoivent « Ava est-elle arrivée? » par les moyens que chacun a choisis : notification de l’appli, courriel ou texto. Si elle est en route, inutile de répondre.',
    'Tras su periodo de gracia, los padres reciben “¿Llegó Ava?” por los canales que eligió cada uno: notificación de la app, correo o mensaje de texto. Si va en camino, no hace falta responder.',
    'आपकी छूट अवधि के बाद, माता-पिता को “क्या Ava पहुँच गई?” उन माध्यमों से मिलता है जो हर अभिभावक ने चुने हैं: ऐप नोटिफ़िकेशन, ईमेल या टेक्स्ट। अगर वह रास्ते में है, तो जवाब देने की ज़रूरत नहीं।'),
'One tap: not attending today': ('Un toucher : absent aujourd’hui', 'Un toque: no asiste hoy', 'एक टैप: आज नहीं आएगा'),
'The parent picks a reason, such as unwell or an appointment. It is saved as today’s absence, the check closes by itself, and the room’s educators and the director are told.': (
    'Le parent choisit une raison, comme une maladie ou un rendez-vous. L’absence du jour est enregistrée, la vérification se ferme d’elle-même, et les éducatrices et éducateurs du local et la direction en sont informés.',
    'El padre elige un motivo, como enfermedad o una cita. Se guarda como la ausencia de hoy, la verificación se cierra sola y se avisa a los educadores del aula y a la dirección.',
    'अभिभावक कोई कारण चुनते हैं, जैसे तबीयत ठीक नहीं या अपॉइंटमेंट। इसे आज की अनुपस्थिति के रूप में सहेजा जाता है, जाँच अपने-आप बंद हो जाती है, और कक्षा के शिक्षकों और डायरेक्टर को बताया जाता है।'),
'No answer? Your team is alerted': ('Pas de réponse? Votre équipe est alertée', '¿Sin respuesta? Se alerta a su equipo', 'कोई जवाब नहीं? आपकी टीम को अलर्ट'),
'If Leo still isn’t signed in and no absence was reported, his room’s educators and the centre’s director get an alert that opens the Safe Arrival board, with the parents’ phone numbers.': (
    'Si l’arrivée de Leo n’est toujours pas enregistrée et qu’aucune absence n’a été signalée, les éducatrices et éducateurs de son local et la direction de la garderie reçoivent une alerte qui ouvre le tableau Safe Arrival, avec les numéros de téléphone des parents.',
    'Si Leo sigue sin registrarse y no se reportó ninguna ausencia, los educadores de su aula y la dirección del centro reciben una alerta que abre el tablero de Safe Arrival, con los teléfonos de los padres.',
    'अगर Leo का अब भी साइन-इन नहीं हुआ है और कोई अनुपस्थिति दर्ज नहीं है, तो उसकी कक्षा के शिक्षकों और सेंटर के डायरेक्टर को एक अलर्ट मिलता है जो माता-पिता के फ़ोन नंबरों के साथ Safe Arrival बोर्ड खोलता है।'),
'Closed, with a record': ('Fermé, avec un registre', 'Cerrado, con un registro', 'बंद, रिकॉर्ड के साथ'),
'Whoever calls records what they found: an absence with a reason, “parent contacted” with a note, or something else. Who closed it, when and why is kept, and written to the audit log.': (
    'La personne qui appelle consigne ce qu’elle a appris : une absence avec sa raison, « parent joint » avec une note, ou autre chose. Qui a fermé le cas, quand et pourquoi est conservé et inscrit au journal d’audit.',
    'Quien llama registra lo que averiguó: una ausencia con su motivo, “padre contactado” con una nota, u otra cosa. Quién lo cerró, cuándo y por qué se guarda y se escribe en el registro de auditoría.',
    'जो फ़ोन करता है, वह दर्ज करता है कि क्या पता चला: कारण के साथ अनुपस्थिति, नोट के साथ “अभिभावक से संपर्क हुआ”, या कुछ और। किसने, कब और क्यों बंद किया, यह सहेजा जाता है और ऑडिट लॉग में लिखा जाता है।'),
'9:00 AM': ('9 h 00', '9:00 a. m.', 'सुबह 9:00'),
'9:15 AM': ('9 h 15', '9:15 a. m.', 'सुबह 9:15'),
'9:17 AM': ('9 h 17', '9:17 a. m.', 'सुबह 9:17'),
'9:35 AM': ('9 h 35', '9:35 a. m.', 'सुबह 9:35'),
'9:41 AM': ('9 h 41', '9:41 a. m.', 'सुबह 9:41'),
'Safe Arrival': ('Safe Arrival', 'Safe Arrival', 'Safe Arrival'),
'Maple Room · today': ('Local des Érables · aujourd’hui', 'Sala Arce · hoy', 'Maple कक्ष · आज'),
'Expected': ('Attendus', 'Esperados', 'अपेक्षित'),
'Arrived': ('Arrivés', 'Llegaron', 'पहुँचे'),
'Not due yet': ('Pas encore dus', 'Aún no toca', 'अभी समय नहीं'),
'Everyone else today': ('Tous les autres aujourd’hui', 'Todos los demás hoy', 'आज बाकी सभी'),
'Ava{0}expected 9:00{/0}': ('Ava{0}attendue à 9 h{/0}', 'Ava{0}esperada a las 9:00{/0}', 'Ava{0}अपेक्षित 9:00{/0}'),
'Leo{0}expected 9:00 (default){/0}': ('Leo{0}attendu à 9 h (par défaut){/0}', 'Leo{0}esperado a las 9:00 (predeterminada){/0}', 'Leo{0}अपेक्षित 9:00 (डिफ़ॉल्ट){/0}'),
'Mia{0}arrived 8:12{/0}': ('Mia{0}arrivée à 8 h 12{/0}', 'Mia{0}llegó a las 8:12{/0}', 'Mia{0}8:12 पर पहुँची{/0}'),
'Noah{0}arrived 8:31{/0}': ('Noah{0}arrivé à 8 h 31{/0}', 'Noah{0}llegó a las 8:31{/0}', 'Noah{0}8:31 पर पहुँचा{/0}'),
'Tuesday': ('mardi', 'martes', 'मंगलवार'),
'now': ('maintenant', 'ahora', 'अभी'),
'🛡️ Has Ava arrived?': ('🛡️ Ava est-elle arrivée?', '🛡️ ¿Llegó Ava?', '🛡️ क्या Ava पहुँच गई?'),
'Ava was expected at Sunflowers Centre around 9:00 AM and hasn’t been signed in. If they’re not coming in today, please tell us in the app (Not attending today). If they’re on the way, no need to reply.': (
    'Ava était attendue à la garderie Les Tournesols vers 9 h et son arrivée n’a pas été enregistrée. Si elle ne vient pas aujourd’hui, dites-le-nous dans l’appli (Absent aujourd’hui). Si elle est en route, inutile de répondre.',
    'Se esperaba a Ava en el Centro Girasoles alrededor de las 9:00 a. m. y no ha sido registrada. Si no viene hoy, avísenos en la app (No asiste hoy). Si va en camino, no hace falta responder.',
    'Ava के Sunflowers सेंटर में लगभग सुबह 9:00 बजे आने की उम्मीद थी और उसका साइन-इन नहीं हुआ है। अगर वह आज नहीं आ रही है, तो कृपया ऐप में बताएँ (आज नहीं आएगा)। अगर वह रास्ते में है, तो जवाब देने की ज़रूरत नहीं।'),
'✉️ Also by email, and 💬 by text if the parent agreed to texts': (
    '✉️ Aussi par courriel, et 💬 par texto si le parent a accepté les textos',
    '✉️ También por correo, y 💬 por mensaje de texto si el padre lo aceptó',
    '✉️ ईमेल से भी, और 💬 टेक्स्ट से, अगर अभिभावक ने टेक्स्ट के लिए सहमति दी है'),
'Ava': ('Ava', 'Ava', 'Ava'),
'Sunflowers Centre · today': ('Garderie Les Tournesols · aujourd’hui', 'Centro Girasoles · hoy', 'Sunflowers सेंटर · आज'),
'✓ Reported absent today · unwell': ('✓ Absence signalée aujourd’hui · malade', '✓ Ausencia reportada hoy · enferma', '✓ आज अनुपस्थित बताया · तबीयत ठीक नहीं'),
'Not attending today': ('Absent aujourd’hui', 'No asiste hoy', 'आज नहीं आएगा'),
'Let the centre know Ava won’t be in.': ('Informez la garderie qu’Ava ne viendra pas.', 'Avise al centro que Ava no vendrá.', 'सेंटर को बताएँ कि Ava नहीं आएगी।'),
'Reason': ('Raison', 'Motivo', 'कारण'),
'🤒 Unwell': ('🤒 Malade', '🤒 Enfermo', '🤒 तबीयत ठीक नहीं'),
'🩺 Appointment': ('🩺 Rendez-vous', '🩺 Cita', '🩺 अपॉइंटमेंट'),
'✈️ Holiday': ('✈️ Vacances', '✈️ Vacaciones', '✈️ छुट्टी'),
'👪 Family': ('👪 Famille', '👪 Familia', '👪 परिवार'),
'• Other': ('• Autre', '• Otro', '• अन्य'),
'Tell the centre': ('Informer la garderie', 'Avisar al centro', 'सेंटर को बताएँ'),
'⚠️ Safe Arrival: Leo not here': ('⚠️ Safe Arrival : Leo n’est pas là', '⚠️ Safe Arrival: Leo no está', '⚠️ Safe Arrival: Leo यहाँ नहीं है'),
'Leo (Maple Room) was expected around 9:00 AM, hasn’t been signed in, and no absence has been reported. The parents were asked and haven’t answered. Please call them.': (
    'Leo (local des Érables) était attendu vers 9 h, son arrivée n’a pas été enregistrée et aucune absence n’a été signalée. Les parents ont été interrogés et n’ont pas répondu. Veuillez les appeler.',
    'Se esperaba a Leo (Sala Arce) alrededor de las 9:00 a. m., no ha sido registrado y no se ha reportado ninguna ausencia. Se preguntó a los padres y no han respondido. Por favor, llámelos.',
    'Leo (Maple कक्ष) के लगभग सुबह 9:00 बजे आने की उम्मीद थी, उसका साइन-इन नहीं हुआ है, और कोई अनुपस्थिति दर्ज नहीं है। माता-पिता से पूछा गया और उन्होंने जवाब नहीं दिया। कृपया उन्हें फ़ोन करें।'),
'Needs attention': ('À traiter', 'Requiere atención', 'ध्यान चाहिए'),
'Leo': ('Leo', 'Leo', 'Leo'),
'{0}Staff alerted{/0}': ('{0}Personnel alerté{/0}', '{0}Personal alertado{/0}', '{0}स्टाफ़ को अलर्ट{/0}'),
'Maple Room · expected 9:00 · parents asked 9:15 · staff alerted 9:35': (
    'Local des Érables · attendu à 9 h · parents interrogés à 9 h 15 · personnel alerté à 9 h 35',
    'Sala Arce · esperado a las 9:00 · padres consultados 9:15 · personal alertado 9:35',
    'Maple कक्ष · अपेक्षित 9:00 · माता-पिता से पूछा 9:15 · स्टाफ़ को अलर्ट 9:35'),
'Dana (Mother) · {0}📞 555-0142{/0}': ('Dana (mère) · {0}📞 555-0142{/0}', 'Dana (madre) · {0}📞 555-0142{/0}', 'Dana (माँ) · {0}📞 555-0142{/0}'),
'Record absence': ('Consigner l’absence', 'Registrar ausencia', 'अनुपस्थिति दर्ज करें'),
'Parent contacted': ('Parent joint', 'Padre contactado', 'अभिभावक से संपर्क हुआ'),
'Other…': ('Autre…', 'Otro…', 'अन्य…'),
'Absent': ('Absents', 'Ausentes', 'अनुपस्थित'),
'Leo · parent contacted': ('Leo · parent joint', 'Leo · padre contactado', 'Leo · अभिभावक से संपर्क हुआ'),
'“Spoke to mom at 9:40, arriving 10:15”': (
    '« Parlé à la mère à 9 h 40, arrivée à 10 h 15 »',
    '“Hablé con la mamá a las 9:40, llega a las 10:15”',
    '“9:40 पर माँ से बात हुई, 10:15 तक पहुँचेंगे”'),
'Closed by Jordan, educator · 9:41 AM': ('Fermé par Jordan, éducatrice · 9 h 41', 'Cerrado por Jordan, educador · 9:41 a. m.', 'Jordan, शिक्षक द्वारा बंद · सुबह 9:41'),
'Saved to the audit log': ('Inscrit au journal d’audit', 'Guardado en el registro de auditoría', 'ऑडिट लॉग में सहेजा गया'),
'Ava{0}reported by parent{/0}': ('Ava{0}signalée par le parent{/0}', 'Ava{0}reportada por el padre{/0}', 'Ava{0}अभिभावक ने बताया{/0}'),
'Absent · unwell': ('Absente · malade', 'Ausente · enferma', 'अनुपस्थित · तबीयत ठीक नहीं'),
'Illustration. Names, centre and numbers are made up.': (
    'Illustration. Les noms, la garderie et les numéros sont fictifs.',
    'Ilustración. Los nombres, el centro y los números son ficticios.',
    'चित्रण। नाम, सेंटर और नंबर काल्पनिक हैं।'),
'Who gets checked': ('Qui est vérifié', 'A quién se revisa', 'किसकी जाँच होती है'),
'Only the children you’re really expecting': ('Seulement les enfants que vous attendez vraiment', 'Solo los niños que realmente espera', 'केवल वही बच्चे जिनकी आप सच में उम्मीद कर रहे हैं'),
'Safe Arrival works from the schedule you already keep in KiddieTrac, so families aren’t messaged about days their child was never coming.': (
    'Safe Arrival s’appuie sur l’horaire que vous tenez déjà dans KiddieTrac : les familles ne reçoivent donc pas de message pour des jours où leur enfant ne devait pas venir.',
    'Safe Arrival trabaja con el horario que ya lleva en KiddieTrac, así que no se escribe a las familias por días en que su hijo nunca iba a venir.',
    'Safe Arrival उसी शेड्यूल से काम करता है जो आप पहले से KiddieTrac में रखते हैं, ताकि परिवारों को उन दिनों के लिए संदेश न जाए जब उनका बच्चा आने वाला ही नहीं था।'),
'Scheduled days only': ('Jours prévus seulement', 'Solo días programados', 'केवल निर्धारित दिन'),
'A child is checked only on the weekdays they’re enrolled for.': (
    'Un enfant n’est vérifié que les jours de semaine où il est inscrit.',
    'Un niño solo se revisa los días de la semana en que está inscrito.',
    'बच्चे की जाँच केवल उन्हीं दिनों होती है जिनके लिए वह नामांकित है।'),
'Open days only': ('Jours d’ouverture seulement', 'Solo días abiertos', 'केवल खुले दिन'),
'Holidays and closures on your calendar are skipped for the whole centre.': (
    'Les congés et fermetures inscrits à votre calendrier sont ignorés pour toute la garderie.',
    'Los feriados y cierres de su calendario se omiten para todo el centro.',
    'आपके कैलेंडर की छुट्टियाँ और बंदी पूरे सेंटर के लिए छोड़ दी जाती हैं।'),
'Each child’s own time': ('L’heure propre à chaque enfant', 'La hora de cada niño', 'हर बच्चे का अपना समय'),
'Set a child’s usual drop-off time on their record. Everyone else uses your default, marked “(default)” on the board.': (
    'Indiquez l’heure d’arrivée habituelle d’un enfant à son dossier. Les autres suivent votre heure par défaut, marquée « (par défaut) » au tableau.',
    'Indique la hora habitual de llegada de un niño en su expediente. Los demás usan su hora predeterminada, marcada “(predeterminada)” en el tablero.',
    'बच्चे के रिकॉर्ड में उसका सामान्य ड्रॉप-ऑफ़ समय सेट करें। बाकी सभी के लिए आपका डिफ़ॉल्ट समय लागू होता है, जो बोर्ड पर “(डिफ़ॉल्ट)” के रूप में दिखता है।'),
'Absences already known': ('Absences déjà connues', 'Ausencias ya conocidas', 'पहले से पता अनुपस्थिति'),
'When a parent has already reported today’s absence, there is nothing to ask about.': (
    'Quand un parent a déjà signalé l’absence du jour, il n’y a rien à demander.',
    'Cuando un padre ya reportó la ausencia de hoy, no hay nada que preguntar.',
    'जब अभिभावक आज की अनुपस्थिति पहले ही बता चुके हों, तो पूछने को कुछ नहीं रहता।'),
'Who hears what': ('Qui est avisé de quoi', 'Quién recibe qué', 'किसे क्या पता चलता है'),
'Parents first, then the people in the room': ('Les parents d’abord, puis l’équipe du local', 'Primero los padres, luego el equipo del aula', 'पहले माता-पिता, फिर कक्षा की टीम'),
'👪 Families': ('👪 Familles', '👪 Familias', '👪 परिवार'),
'Asked once, when the grace period after the expected time has passed.': (
    'Interrogées une seule fois, quand le délai de grâce après l’heure prévue est écoulé.',
    'Se les pregunta una sola vez, cuando ha pasado el periodo de gracia tras la hora esperada.',
    'केवल एक बार पूछा जाता है, जब अपेक्षित समय के बाद की छूट अवधि बीत जाती है।'),
'The message goes through each parent’s own choices: app notification, email, or a text if they agreed to texts.': (
    'Le message suit les préférences de chaque parent : notification de l’appli, courriel, ou texto s’il a accepté les textos.',
    'El mensaje sigue las preferencias de cada padre: notificación de la app, correo, o mensaje de texto si lo aceptó.',
    'संदेश हर अभिभावक की अपनी पसंद से जाता है: ऐप नोटिफ़िकेशन, ईमेल, या टेक्स्ट, अगर उन्होंने टेक्स्ट के लिए सहमति दी है।'),
'One tap on “Not attending today”, with a reason, records the absence and stops the messages.': (
    'Un toucher sur « Absent aujourd’hui », avec une raison, consigne l’absence et arrête les messages.',
    'Un toque en “No asiste hoy”, con un motivo, registra la ausencia y detiene los mensajes.',
    'कारण के साथ “आज नहीं आएगा” पर एक टैप अनुपस्थिति दर्ज करता है और संदेश रोक देता है।'),
'If the child is on the way, no reply is needed: the check closes when they’re signed in.': (
    'Si l’enfant est en route, aucune réponse n’est nécessaire : la vérification se ferme dès que son arrivée est enregistrée.',
    'Si el niño va en camino, no hace falta responder: la verificación se cierra cuando se registra su llegada.',
    'अगर बच्चा रास्ते में है, तो जवाब की ज़रूरत नहीं: साइन-इन होते ही जाँच बंद हो जाती है।'),
'Text messages use the SMS & voice alerts add-on. Safe Arrival works without it through the app and email.': (
    'Les textos passent par l’option Alertes SMS et vocales. Sans elle, Safe Arrival fonctionne par l’appli et par courriel.',
    'Los mensajes de texto usan el complemento de alertas por SMS y voz. Sin él, Safe Arrival funciona por la app y el correo.',
    'टेक्स्ट संदेश SMS और वॉइस अलर्ट ऐड-ऑन से जाते हैं। इसके बिना भी Safe Arrival ऐप और ईमेल से काम करता है।'),
'🧑‍🏫 Your team': ('🧑‍🏫 Votre équipe', '🧑‍🏫 Su equipo', '🧑‍🏫 आपकी टीम'),
'If the parents haven’t answered by your escalation time, the room’s educators and the centre’s director are alerted.': (
    'Si les parents n’ont pas répondu avant votre délai d’escalade, les éducatrices et éducateurs du local et la direction de la garderie sont alertés.',
    'Si los padres no han respondido para su hora de escalamiento, se alerta a los educadores del aula y a la dirección del centro.',
    'अगर आपके एस्केलेशन समय तक माता-पिता ने जवाब नहीं दिया, तो कक्षा के शिक्षकों और सेंटर के डायरेक्टर को अलर्ट किया जाता है।'),
'The alert opens the Safe Arrival board, with each guardian’s name and a tap-to-call number.': (
    'L’alerte ouvre le tableau Safe Arrival, avec le nom de chaque tuteur et un numéro à toucher pour appeler.',
    'La alerta abre el tablero de Safe Arrival, con el nombre de cada tutor y un número para llamar con un toque.',
    'अलर्ट Safe Arrival बोर्ड खोलता है, हर अभिभावक के नाम और टैप करके कॉल करने वाले नंबर के साथ।'),
'Anyone a phone notification can’t reach gets the alert by email, and the board names them.': (
    'Toute personne qu’une notification sur téléphone ne peut joindre reçoit l’alerte par courriel, et le tableau indique son nom.',
    'Quien no reciba la notificación en el teléfono recibe la alerta por correo, y el tablero muestra su nombre.',
    'जिस तक फ़ोन नोटिफ़िकेशन नहीं पहुँचता, उसे अलर्ट ईमेल से मिलता है, और बोर्ड उसका नाम दिखाता है।'),
'When a parent reports an absence, the room’s educators and the director are told too.': (
    'Quand un parent signale une absence, les éducatrices et éducateurs du local et la direction en sont aussi informés.',
    'Cuando un padre reporta una ausencia, también se avisa a los educadores del aula y a la dirección.',
    'जब कोई अभिभावक अनुपस्थिति बताते हैं, तो कक्षा के शिक्षकों और डायरेक्टर को भी बताया जाता है।'),
'Staff alerts go out even if a family has turned notifications off: a missing child is the centre’s business.': (
    'Les alertes au personnel partent même si une famille a désactivé ses notifications : un enfant manquant, c’est l’affaire de la garderie.',
    'Las alertas al personal se envían aunque una familia haya desactivado sus notificaciones: un niño que falta es asunto del centro.',
    'स्टाफ़ अलर्ट तब भी जाते हैं जब किसी परिवार ने नोटिफ़िकेशन बंद किए हों: लापता बच्चा सेंटर की ज़िम्मेदारी है।'),
'The board': ('Le tableau', 'El tablero', 'बोर्ड'),
'Who still needs a call, at a glance': ('Qui doit encore être appelé, en un coup d’œil', 'A quién falta llamar, de un vistazo', 'किसे अभी फ़ोन करना बाकी है, एक नज़र में'),
'Expected today': ('Attendus aujourd’hui', 'Esperados hoy', 'आज अपेक्षित'),
'Leo{0}Maple Room · expected 9:00 · parents asked 9:15{/0}': (
    'Leo{0}Local des Érables · attendu à 9 h · parents interrogés à 9 h 15{/0}',
    'Leo{0}Sala Arce · esperado a las 9:00 · padres consultados 9:15{/0}',
    'Leo{0}Maple कक्ष · अपेक्षित 9:00 · माता-पिता से पूछा 9:15{/0}'),
'Overdue': ('En retard', 'Atrasado', 'देर हो गई'),
'Ava{0}unwell{/0}': ('Ava{0}malade{/0}', 'Ava{0}enferma{/0}', 'Ava{0}तबीयत ठीक नहीं{/0}'),
'{0}Needs attention comes first.{/0} Overdue children are at the top, with the ones staff have already been alerted about first.': (
    '{0}Les cas à traiter d’abord.{/0} Les enfants en retard sont en haut, en commençant par ceux pour qui le personnel a déjà été alerté.',
    '{0}Primero lo que requiere atención.{/0} Los niños atrasados aparecen arriba, empezando por aquellos sobre los que ya se alertó al personal.',
    '{0}ध्यान चाहने वाले सबसे ऊपर।{/0} देर वाले बच्चे सबसे ऊपर होते हैं, और उनमें भी पहले वे जिनके लिए स्टाफ़ को अलर्ट जा चुका है।'),
'{0}Close each case in a tap.{/0} Record an absence with a reason, mark “parent contacted” with a note such as “arriving 10:15”, or choose other.': (
    '{0}Fermez chaque cas d’un toucher.{/0} Consignez une absence avec sa raison, indiquez « parent joint » avec une note comme « arrivée à 10 h 15 », ou choisissez autre.',
    '{0}Cierre cada caso con un toque.{/0} Registre una ausencia con su motivo, marque “padre contactado” con una nota como “llega a las 10:15”, o elija otro.',
    '{0}हर मामला एक टैप में बंद करें।{/0} कारण के साथ अनुपस्थिति दर्ज करें, “10:15 तक पहुँचेंगे” जैसे नोट के साथ “अभिभावक से संपर्क हुआ” चुनें, या अन्य चुनें।'),
'{0}Everyone else today.{/0} Who has arrived and when, who is absent and why, and who isn’t due yet. The board refreshes every minute.': (
    '{0}Tous les autres aujourd’hui.{/0} Qui est arrivé et à quelle heure, qui est absent et pourquoi, et qui n’est pas encore dû. Le tableau se met à jour chaque minute.',
    '{0}Todos los demás hoy.{/0} Quién llegó y cuándo, quién está ausente y por qué, y a quién aún no le toca. El tablero se actualiza cada minuto.',
    '{0}आज बाकी सभी।{/0} कौन कब पहुँचा, कौन अनुपस्थित है और क्यों, और किसका समय अभी नहीं हुआ। बोर्ड हर मिनट अपडेट होता है।'),
'{0}The right view for each role.{/0} Educators see their own rooms, directors their centres, and agency admins every centre with a filter.': (
    '{0}La bonne vue pour chaque rôle.{/0} Les éducatrices et éducateurs voient leurs propres locaux, la direction ses garderies, et les administrateurs de l’agence toutes les garderies, avec un filtre.',
    '{0}La vista adecuada para cada rol.{/0} Los educadores ven sus propias aulas, los directores sus centros y los administradores de la agencia todos los centros, con un filtro.',
    '{0}हर भूमिका के लिए सही दृश्य।{/0} शिक्षक अपनी कक्षाएँ देखते हैं, डायरेक्टर अपने सेंटर, और एजेंसी एडमिन फ़िल्टर के साथ सभी सेंटर।'),
'Illustration. Names and numbers are made up.': ('Illustration. Les noms et les chiffres sont fictifs.', 'Ilustración. Los nombres y las cifras son ficticios.', 'चित्रण। नाम और संख्याएँ काल्पनिक हैं।'),
'Your rules': ('Vos règles', 'Sus reglas', 'आपके नियम'),
'Settings that match your safe arrival policy': (
    'Des réglages adaptés à votre politique d’arrivée sécuritaire',
    'Ajustes que se adaptan a su política de llegada segura',
    'आपकी सुरक्षित आगमन नीति के अनुसार सेटिंग्स'),
'Safe Arrival is off until an agency admin turns it on, because it messages parents. Only an agency admin can change it, and every change is written to the audit log.': (
    'Safe Arrival reste désactivé jusqu’à ce qu’un administrateur de l’agence l’active, parce qu’il écrit aux parents. Seul un administrateur de l’agence peut le modifier, et chaque changement est inscrit au journal d’audit.',
    'Safe Arrival está desactivado hasta que un administrador de la agencia lo activa, porque envía mensajes a los padres. Solo un administrador de la agencia puede cambiarlo, y cada cambio se escribe en el registro de auditoría.',
    'Safe Arrival तब तक बंद रहता है जब तक कोई एजेंसी एडमिन इसे चालू न करे, क्योंकि यह माता-पिता को संदेश भेजता है। केवल एजेंसी एडमिन इसे बदल सकते हैं, और हर बदलाव ऑडिट लॉग में लिखा जाता है।'),
'Ask parents after': ('Interroger les parents après', 'Preguntar a los padres tras', 'माता-पिता से पूछें'),
'minutes past the expected time': ('minutes après l’heure prévue', 'minutos después de la hora esperada', 'अपेक्षित समय के इतने मिनट बाद'),
'15 min': ('15 min', '15 min', '15 मिनट'),
'Alert staff after': ('Alerter le personnel après', 'Alertar al personal tras', 'स्टाफ़ को अलर्ट करें'),
'minutes with no answer from parents': ('minutes sans réponse des parents', 'minutos sin respuesta de los padres', 'माता-पिता का जवाब न आने के इतने मिनट बाद'),
'20 min': ('20 min', '20 min', '20 मिनट'),
'Default expected time': ('Heure prévue par défaut', 'Hora esperada predeterminada', 'डिफ़ॉल्ट अपेक्षित समय'),
'for children without their own': ('pour les enfants qui n’en ont pas', 'para los niños sin hora propia', 'उन बच्चों के लिए जिनका अपना समय नहीं है'),
'Each child’s drop-off time': ('Heure d’arrivée de chaque enfant', 'Hora de llegada de cada niño', 'हर बच्चे का ड्रॉप-ऑफ़ समय'),
'on their record, under usual times': ('à son dossier, sous Heures habituelles', 'en su expediente, en Horarios habituales', 'उसके रिकॉर्ड में, सामान्य समय के अंतर्गत'),
'per child': ('par enfant', 'por niño', 'हर बच्चे के लिए'),
'Defaults shown. Parents can be asked from 0 to 180 minutes after the expected time, and staff alerted 5 to 240 minutes later.': (
    'Valeurs par défaut affichées. Les parents peuvent être interrogés de 0 à 180 minutes après l’heure prévue, et le personnel alerté de 5 à 240 minutes plus tard.',
    'Se muestran los valores predeterminados. Se puede preguntar a los padres de 0 a 180 minutos después de la hora esperada, y alertar al personal de 5 a 240 minutos más tarde.',
    'डिफ़ॉल्ट दिखाए गए हैं। माता-पिता से अपेक्षित समय के 0 से 180 मिनट बाद पूछा जा सकता है, और स्टाफ़ को उसके 5 से 240 मिनट बाद अलर्ट किया जा सकता है।'),
'🧾 A record of every check': ('🧾 Un registre de chaque vérification', '🧾 Un registro de cada verificación', '🧾 हर जाँच का रिकॉर्ड'),
'When the parents were asked, and how many were reached.': (
    'Quand les parents ont été interrogés, et combien ont été joints.',
    'Cuándo se preguntó a los padres y a cuántos se llegó.',
    'माता-पिता से कब पूछा गया, और कितनों तक संदेश पहुँचा।'),
'When staff were alerted, and whom a phone notification didn’t reach.': (
    'Quand le personnel a été alerté, et qui la notification sur téléphone n’a pas joint.',
    'Cuándo se alertó al personal y a quién no le llegó la notificación en el teléfono.',
    'स्टाफ़ को कब अलर्ट किया गया, और किस तक फ़ोन नोटिफ़िकेशन नहीं पहुँचा।'),
'How it was closed, by whom, and with what note. Closing a case is written to the audit log.': (
    'Comment le cas a été fermé, par qui et avec quelle note. La fermeture d’un cas est inscrite au journal d’audit.',
    'Cómo se cerró, por quién y con qué nota. El cierre de cada caso se escribe en el registro de auditoría.',
    'मामला कैसे, किसने और किस नोट के साथ बंद किया। मामला बंद करना ऑडिट लॉग में लिखा जाता है।'),
'🔕 One message, at the right time': ('🔕 Un seul message, au bon moment', '🔕 Un solo mensaje, en el momento justo', '🔕 एक ही संदेश, सही समय पर'),
'With Safe Arrival on, it replaces KiddieTrac’s fixed morning “has your child arrived?” reminder, so parents get one message, not two.': (
    'Une fois activé, Safe Arrival remplace le rappel matinal fixe « votre enfant est-il arrivé? » de KiddieTrac : les parents reçoivent un seul message, pas deux.',
    'Con Safe Arrival activado, reemplaza el recordatorio fijo de la mañana “¿llegó su hijo?” de KiddieTrac, así los padres reciben un mensaje, no dos.',
    'Safe Arrival चालू होने पर यह KiddieTrac के सुबह के तय “क्या आपका बच्चा पहुँच गया?” रिमाइंडर की जगह ले लेता है, ताकि माता-पिता को दो नहीं, एक ही संदेश मिले।'),
'Nothing is sent on weekends, holidays or days a child isn’t scheduled.': (
    'Rien n’est envoyé les fins de semaine, les jours fériés ou les jours où l’enfant n’est pas prévu.',
    'No se envía nada los fines de semana, feriados ni los días en que el niño no está programado.',
    'सप्ताहांत, छुट्टियों या उन दिनों कुछ नहीं भेजा जाता जब बच्चा निर्धारित नहीं है।'),
'A reported absence counts everywhere: the roster, reminders and the parent app see it.': (
    'Une absence signalée compte partout : la liste de présence, les rappels et l’appli des parents en tiennent compte.',
    'Una ausencia reportada cuenta en todas partes: la lista, los recordatorios y la app de los padres la ven.',
    'बताई गई अनुपस्थिति हर जगह गिनी जाती है: रोस्टर, रिमाइंडर और पैरेंट ऐप सभी उसे देखते हैं।'),
'Questions about Safe Arrival': ('Questions sur Safe Arrival', 'Preguntas sobre Safe Arrival', 'Safe Arrival के बारे में सवाल'),
'Who gets the first message?': ('Qui reçoit le premier message?', '¿Quién recibe el primer mensaje?', 'पहला संदेश किसे मिलता है?'),
'The child’s parents and guardians, once, through the channels each of them chose in their profile: app notification, email, or a text message if they agreed to texts. If they answer “Not attending today”, nothing else is sent.': (
    'Les parents et tuteurs de l’enfant, une seule fois, par les moyens que chacun a choisis dans son profil : notification de l’appli, courriel, ou texto s’il a accepté les textos. S’ils répondent « Absent aujourd’hui », rien d’autre n’est envoyé.',
    'Los padres y tutores del niño, una sola vez, por los canales que cada uno eligió en su perfil: notificación de la app, correo, o mensaje de texto si lo aceptó. Si responden “No asiste hoy”, no se envía nada más.',
    'बच्चे के माता-पिता और अभिभावकों को, केवल एक बार, उन माध्यमों से जो हर किसी ने अपनी प्रोफ़ाइल में चुने हैं: ऐप नोटिफ़िकेशन, ईमेल, या टेक्स्ट संदेश, अगर उन्होंने टेक्स्ट के लिए सहमति दी है। अगर वे “आज नहीं आएगा” बताते हैं, तो और कुछ नहीं भेजा जाता।'),
'Who is alerted when the parents don’t answer?': ('Qui est alerté quand les parents ne répondent pas?', '¿A quién se alerta si los padres no responden?', 'माता-पिता जवाब न दें तो किसे अलर्ट किया जाता है?'),
'The educators assigned to the child’s room and the centre’s director, in the app and as a phone notification. If no one is attached to that room or centre, the agency’s admins are alerted, so an alert never goes nowhere.': (
    'Les éducatrices et éducateurs affectés au local de l’enfant et la direction de la garderie, dans l’appli et par notification sur téléphone. Si personne n’est rattaché à ce local ou à cette garderie, les administrateurs de l’agence sont alertés : une alerte n’est jamais perdue.',
    'Los educadores asignados al aula del niño y la dirección del centro, en la app y como notificación en el teléfono. Si nadie está asignado a esa aula o centro, se alerta a los administradores de la agencia, para que ninguna alerta se pierda.',
    'बच्चे की कक्षा से जुड़े शिक्षकों और सेंटर के डायरेक्टर को, ऐप में और फ़ोन नोटिफ़िकेशन के रूप में। अगर उस कक्षा या सेंटर से कोई जुड़ा नहीं है, तो एजेंसी के एडमिन को अलर्ट किया जाता है, ताकि कोई अलर्ट कहीं खो न जाए।'),
'What if an educator’s phone notifications are off?': (
    'Et si les notifications d’une éducatrice sont désactivées?',
    '¿Y si un educador tiene las notificaciones desactivadas?',
    'अगर किसी शिक्षक के फ़ोन नोटिफ़िकेशन बंद हों तो?'),
'Anyone the phone notification doesn’t reach gets the same alert by email, and the Safe Arrival board shows their name, so the director can see who wasn’t reached.': (
    'Toute personne que la notification sur téléphone ne joint pas reçoit la même alerte par courriel, et le tableau Safe Arrival affiche son nom, pour que la direction voie qui n’a pas été joint.',
    'Quien no reciba la notificación en el teléfono recibe la misma alerta por correo, y el tablero de Safe Arrival muestra su nombre, para que la dirección vea a quién no se llegó.',
    'जिस तक फ़ोन नोटिफ़िकेशन नहीं पहुँचता, उसे वही अलर्ट ईमेल से मिलता है, और Safe Arrival बोर्ड उसका नाम दिखाता है, ताकि डायरेक्टर देख सकें कि किस तक अलर्ट नहीं पहुँचा।'),
'Does it run on holidays or days a child doesn’t attend?': (
    'Fonctionne-t-il les jours fériés ou les jours où l’enfant ne vient pas?',
    '¿Funciona en feriados o en días en que el niño no asiste?',
    'क्या यह छुट्टियों या उन दिनों चलता है जब बच्चा नहीं आता?'),
'No. Only children scheduled for that weekday are checked, and only at a centre that is open that day. Closures and holidays on your calendar are skipped, and a child already reported absent is never asked about.': (
    'Non. Seuls les enfants prévus ce jour de semaine sont vérifiés, et seulement dans une garderie ouverte ce jour-là. Les fermetures et congés inscrits à votre calendrier sont ignorés, et on ne pose jamais de question sur un enfant dont l’absence est déjà signalée.',
    'No. Solo se revisa a los niños programados para ese día de la semana, y solo en un centro abierto ese día. Los cierres y feriados de su calendario se omiten, y nunca se pregunta por un niño cuya ausencia ya se reportó.',
    'नहीं। केवल उस दिन के लिए निर्धारित बच्चों की जाँच होती है, और केवल उस दिन खुले सेंटर में। आपके कैलेंडर की बंदी और छुट्टियाँ छोड़ दी जाती हैं, और जिस बच्चे की अनुपस्थिति पहले से बताई गई हो, उसके बारे में कभी नहीं पूछा जाता।'),
'Do we need the SMS add-on?': ('Faut-il l’option SMS?', '¿Necesitamos el complemento de SMS?', 'क्या हमें SMS ऐड-ऑन चाहिए?'),
'No. Safe Arrival is part of KiddieTrac and works with app notifications and email. Text messages to parents use the SMS & voice alerts add-on, and only go to parents who agreed to texts.': (
    'Non. Safe Arrival fait partie de KiddieTrac et fonctionne avec les notifications de l’appli et le courriel. Les textos aux parents passent par l’option Alertes SMS et vocales, et ne vont qu’aux parents qui ont accepté les textos.',
    'No. Safe Arrival es parte de KiddieTrac y funciona con notificaciones de la app y correo. Los mensajes de texto a los padres usan el complemento de alertas por SMS y voz, y solo llegan a los padres que los aceptaron.',
    'नहीं। Safe Arrival KiddieTrac का हिस्सा है और ऐप नोटिफ़िकेशन और ईमेल से काम करता है। माता-पिता को टेक्स्ट संदेश SMS और वॉइस अलर्ट ऐड-ऑन से जाते हैं, और केवल उन्हीं को जिन्होंने टेक्स्ट के लिए सहमति दी है।'),
'Can educators turn it off or change the times?': (
    'Les éducatrices peuvent-elles le désactiver ou changer les heures?',
    '¿Pueden los educadores desactivarlo o cambiar los tiempos?',
    'क्या शिक्षक इसे बंद कर सकते हैं या समय बदल सकते हैं?'),
'No. Safe Arrival is off until an agency admin turns it on, and only an agency admin can change its settings. Every change is written to the audit log.': (
    'Non. Safe Arrival reste désactivé jusqu’à ce qu’un administrateur de l’agence l’active, et seul un administrateur de l’agence peut modifier ses réglages. Chaque changement est inscrit au journal d’audit.',
    'No. Safe Arrival está desactivado hasta que un administrador de la agencia lo activa, y solo un administrador de la agencia puede cambiar sus ajustes. Cada cambio se escribe en el registro de auditoría.',
    'नहीं। Safe Arrival तब तक बंद रहता है जब तक कोई एजेंसी एडमिन इसे चालू न करे, और केवल एजेंसी एडमिन इसकी सेटिंग्स बदल सकते हैं। हर बदलाव ऑडिट लॉग में लिखा जाता है।'),
'Know who’s missing before anyone has to ask': (
    'Sachez qui manque avant que quiconque ait à le demander',
    'Sepa quién falta antes de que nadie tenga que preguntar',
    'किसी के पूछने से पहले जानें कि कौन नहीं आया'),
'See Safe Arrival on your own rooms in a short demo. Free 14-day trial, no credit card required.': (
    'Voyez Safe Arrival avec vos propres locaux lors d’une courte démo. Essai gratuit de 14 jours, sans carte de crédit.',
    'Vea Safe Arrival con sus propias aulas en una demostración breve. Prueba gratuita de 14 días, sin tarjeta de crédito.',
    'एक छोटे डेमो में अपनी ही कक्षाओं पर Safe Arrival देखें। 14 दिन का मुफ़्त ट्रायल, क्रेडिट कार्ड की ज़रूरत नहीं।'),
'When a child expected today hasn’t been signed in and no absence was reported, KiddieTrac asks the parents, then alerts the room’s educators and the director if nobody answers. Anyone a phone notification can’t reach gets the alert by email. A live board shows who still needs a call, and every check is recorded. {0}See Safe Arrival{/0}': (
    'Quand l’arrivée d’un enfant attendu aujourd’hui n’a pas été enregistrée et qu’aucune absence n’a été signalée, KiddieTrac le demande aux parents, puis alerte les éducatrices et éducateurs du local et la direction si personne ne répond. Toute personne qu’une notification sur téléphone ne peut joindre reçoit l’alerte par courriel. Un tableau en direct indique qui doit encore être appelé, et chaque vérification est consignée. {0}Voir Safe Arrival{/0}',
    'Cuando un niño esperado hoy no ha sido registrado y no se reportó ninguna ausencia, KiddieTrac pregunta a los padres y luego alerta a los educadores del aula y a la dirección si nadie responde. Quien no reciba la notificación en el teléfono recibe la alerta por correo. Un tablero en vivo muestra a quién falta llamar, y cada verificación queda registrada. {0}Ver Safe Arrival{/0}',
    'जब आज अपेक्षित किसी बच्चे का साइन-इन नहीं हुआ हो और कोई अनुपस्थिति दर्ज न हो, तो KiddieTrac माता-पिता से पूछता है, फिर कोई जवाब न दे तो कक्षा के शिक्षकों और डायरेक्टर को अलर्ट करता है। जिस तक फ़ोन नोटिफ़िकेशन नहीं पहुँचता, उसे अलर्ट ईमेल से मिलता है। एक लाइव बोर्ड दिखाता है कि किसे अभी फ़ोन करना बाकी है, और हर जाँच दर्ज होती है। {0}Safe Arrival देखें{/0}'),
'{0}Safe Arrival{/0}': ('{0}Safe Arrival{/0}', '{0}Safe Arrival{/0}', '{0}Safe Arrival{/0}'),
}
