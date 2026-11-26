/*
 * KiddieTrac — site-wide translation (English / Français / Español / हिन्दी).
 *
 * The portal has ~100 screen modules with their English strings written inline, so there
 * is no message catalogue to swap. This translates the rendered DOM instead: after each
 * render it walks the sidebar, the top bar and the screen, and replaces text it
 * RECOGNISES from the dictionary below.
 *
 * The dictionary is exact-match on purpose. A child's name, a family name, an audit-log
 * entry, a lesson-plan title — none of it is in the dictionary, so none of it is ever
 * touched. Anything not in the dictionary simply stays in English; nothing is mangled,
 * and adding a phrase here translates it everywhere it appears, desktop and mobile
 * (both render the same DOM).
 *
 * Translated nodes are remembered, so repeat sweeps make no further DOM changes — that
 * matters: a sweep that kept mutating would fight screens that re-render on mutation.
 */
(function (window) {
  'use strict';
  if (window.KT_I18N_LOADED) return;
  window.KT_I18N_LOADED = true;

  // ── Dictionary ─────────────────────────────────────────────────────────────
  // key = the exact English string as it appears in the UI (emoji prefixes are handled
  // separately, so write the words only).
  /* THE DICTIONARY LOADS ONLY FOR ANOTHER LANGUAGE (2026-11-13, Anthony: "when the portal refreshes or saves that
     process moves very slow - full sweep"). It lives in js/kt-i18n-base.js (590 KB), fetched when a language other
     than English is on, ahead of that language's pack (KT_I18N_BASE below). Until it arrives KT.i18n.t() answers in
     English and the page stays English; the sweep runs again the moment it lands. */
  var DICT = {};

  var LOCALES = ['en', 'fr', 'es', 'hi'];

  function locale() {
    try {
      var l = localStorage.getItem('kt_locale') || 'en';
      return LOCALES.indexOf(l) === -1 ? 'en' : l;
    } catch (e) { return 'en'; }
  }

  // Leading emoji / symbols are kept as-is; only the words are looked up. That way
  // "📢 Announcements" and "Announcements" share one dictionary entry.
  var LEAD = /^([^\p{L}\p{N}]*)(.*)$/u;   // strip a leading emoji/symbol; it is re-attached to the output

  // Case-insensitive index. The sidebar prints "Overview" and CSS upper-cases it, the top
  // bar prints "Super Admin" — guessing the casing of every string was never going to
  // work, so match on the lower-cased text.
  var CI = {};
  (function () {
    for (var k in DICT) if (DICT.hasOwnProperty(k)) CI[k.toLowerCase()] = DICT[k];
  })();

  function lookup(words) {
    return DICT[words] || CI[String(words).toLowerCase()] || null;
  }

  /* TRANSLATION PACKS (2026-11-05, Anthony: "update help and guides and portal with translations to all
     language types that we support - full sweep"). The ~12,000 strings that sweep added live in one file per
     language, js/kt-i18n-pack-<lang>.js = KT_I18N_PACK('<lang>', { English: translation, ... }), fetched only
     while that language is on, so an English session downloads nothing extra. A pack fills gaps only: an
     entry written in DICT above always wins. New UI text can still go straight into DICT. */
  var PACK_V = '20261126brc';
  var BASE_V = '20261126rcui';   // + report card menu, email, Generate with AI tab (2026-11-26)
  var packAsked = {};
  var baseState = 0, afterBase = [];   // 0 not asked, 1 on its way, 2 in
  window.KT_I18N_BASE = function (map) {
    for (var k in map) {
      if (!Object.prototype.hasOwnProperty.call(map, k)) continue;
      var e = DICT[k] || (DICT[k] = {});
      for (var l in map[k]) if (Object.prototype.hasOwnProperty.call(map[k], l)) e[l] = map[k][l];   // the dictionary wins
    }
    CI = {};
    for (var c in DICT) if (DICT.hasOwnProperty(c)) CI[c.toLowerCase()] = DICT[c];
    baseState = 2;
    var q = afterBase; afterBase = [];
    q.forEach(function (fn) { try { fn(); } catch (x) {} });
    done = new WeakSet();
    try { apply(); } catch (x) {}
  };
  function loadBase(then) {
    if (baseState === 2) return then();
    afterBase.push(then);
    if (baseState === 1) return;
    baseState = 1;
    try {
      var sc = document.createElement('script');
      sc.src = '/js/kt-i18n-base.js?v=' + BASE_V;
      sc.async = true;
      sc.onerror = function () { baseState = 0; afterBase = []; packAsked = {}; };
      (document.head || document.documentElement).appendChild(sc);
    } catch (e) { baseState = 0; afterBase = []; packAsked = {}; }
  }
  window.KT_I18N_PACK = function (lang, map) {
    if (LOCALES.indexOf(lang) === -1 || !map) return;
    for (var k in map) {
      if (!Object.prototype.hasOwnProperty.call(map, k) || !map[k]) continue;
      var lk = k.toLowerCase();
      var e = DICT[k] || CI[lk];
      if (!e) { e = {}; DICT[k] = e; }
      if (!CI[lk]) CI[lk] = e;
      if (!e[lang]) e[lang] = map[k];
    }
    // Nodes swept before the pack arrived were marked done; look at everything once more.
    done = new WeakSet();
    try { apply(); } catch (x) {}
  };
  function loadPack(loc) {
    if (loc === 'en' || packAsked[loc]) return;
    packAsked[loc] = 1;
    loadBase(function () {   // the dictionary first, so its entries win over the pack's
      try {
        var sc = document.createElement('script');
        sc.src = '/js/kt-i18n-pack-' + loc + '.js?v=' + PACK_V;
        sc.async = true;
        sc.onerror = function () { packAsked[loc] = 0; };
        (document.head || document.documentElement).appendChild(sc);
      } catch (e) { packAsked[loc] = 0; }
    });
  }

  // Number-glued templates. § marks a number run; numbers are reinserted positionally.
  var PATTERNS = {
    // 2026-11-03 educator figures say what they count
    '§ in · § picked up · § not in yet': { fr: '§ présents · § partis · § pas encore arrivés', es: '§ dentro · § recogidos · § aún sin llegar', hi: '§ अंदर · § ले जाए गए · § अभी नहीं आए' },
    '§ in · § picked up · § not in': { fr: '§ présents · § partis · § absents', es: '§ dentro · § recogidos · § no vinieron', hi: '§ अंदर · § ले जाए गए · § नहीं आए' },
    '§ enrolled at centre': { fr: '§ inscrits au centre', es: '§ matriculados en el centro', hi: 'केंद्र में § नामांकित' },
    '§ enrolled at centre · § not booked today': { fr: '§ inscrits au centre · § non prévus aujourd’hui', es: '§ matriculados en el centro · § sin reserva hoy', hi: 'केंद्र में § नामांकित · आज § निर्धारित नहीं' },
    '§ enrolled at your centres': { fr: '§ inscrits dans vos centres', es: '§ matriculados en sus centros', hi: 'आपके केंद्रों में § नामांकित' },
    '§ enrolled at your centres · § not booked today': { fr: '§ inscrits dans vos centres · § non prévus aujourd’hui', es: '§ matriculados en sus centros · § sin reserva hoy', hi: 'आपके केंद्रों में § नामांकित · आज § निर्धारित नहीं' },
    '§ children here · § educators on': { fr: '§ enfants présents · § éducateurs en poste', es: '§ niños aquí · § educadores de turno', hi: '§ बच्चे यहाँ · § शिक्षक ड्यूटी पर' },
    '§ child here · § educators on': { fr: '§ enfant présent · § éducateurs en poste', es: '§ niño aquí · § educadores de turno', hi: '§ बच्चा यहाँ · § शिक्षक ड्यूटी पर' },
    '§ children here · § educator on': { fr: '§ enfants présents · § éducateur en poste', es: '§ niños aquí · § educador de turno', hi: '§ बच्चे यहाँ · § शिक्षक ड्यूटी पर' },
    '§ child here · § educator on': { fr: '§ enfant présent · § éducateur en poste', es: '§ niño aquí · § educador de turno', hi: '§ बच्चा यहाँ · § शिक्षक ड्यूटी पर' },
    '§ children here · § educators on · § needed': { fr: '§ enfants présents · § éducateurs en poste · § requis', es: '§ niños aquí · § educadores de turno · § necesarios', hi: '§ बच्चे यहाँ · § शिक्षक ड्यूटी पर · § आवश्यक' },
    '§ child here · § educators on · § needed': { fr: '§ enfant présent · § éducateurs en poste · § requis', es: '§ niño aquí · § educadores de turno · § necesarios', hi: '§ बच्चा यहाँ · § शिक्षक ड्यूटी पर · § आवश्यक' },
    '§ children here · § educator on · § needed': { fr: '§ enfants présents · § éducateur en poste · § requis', es: '§ niños aquí · § educador de turno · § necesarios', hi: '§ बच्चे यहाँ · § शिक्षक ड्यूटी पर · § आवश्यक' },
    '§ child here · § educator on · § needed': { fr: '§ enfant présent · § éducateur en poste · § requis', es: '§ niño aquí · § educador de turno · § necesarios', hi: '§ बच्चा यहाँ · § शिक्षक ड्यूटी पर · § आवश्यक' },
    'in § days': { fr: 'dans § jours', es: 'en § días', hi: '§ दिनों में' },
    'Waiting: § days': { fr: 'En attente : § jours', es: 'Esperando: § días', hi: 'प्रतीक्षा: § दिन' },
    '§ families': { fr: '§ familles', es: '§ familias', hi: '§ परिवार' },
    '§ enrolled · § families': { fr: '§ inscrits · § familles', es: '§ matriculados · § familias', hi: '§ नामांकित · § परिवार' },
    'Est. § month lifetime': { fr: 'Durée de vie estimée à § mois', es: 'Vida útil estimada de § meses', hi: 'अनुमानित § माह जीवनकाल' },
    'Per active agency / month': { fr: 'Par agence active / mois', es: 'Por agencia activa / mes', hi: 'प्रति सक्रिय एजेंसी / माह' },
    'Per enrolled child / month': { fr: 'Par enfant inscrit / mois', es: 'Por niño matriculado / mes', hi: 'प्रति नामांकित बच्चा / माह' },
    '§ of capacity': { fr: '§ de la capacité', es: '§ de la capacidad', hi: '§ क्षमता का' },
    'of § licensed spaces': { fr: 'sur § places autorisées', es: 'de § plazas autorizadas', hi: '§ लाइसेंस स्थानों में से' },
    '§ / § licensed seats': { fr: '§ / § places autorisées', es: '§ / § plazas autorizadas', hi: '§ / § लाइसेंस स्थान' },
    '§ spaces open': { fr: '§ places libres', es: '§ plazas libres', hi: '§ स्थान खाली' },
    '§ enrolled': { fr: '§ inscrits', es: '§ matriculados', hi: '§ नामांकित' },
    '/ § enrolled': { fr: '/ § inscrits', es: '/ § matriculados', hi: '/ § नामांकित' },
    '§ of enrolled': { fr: '§ des inscrits', es: '§ de los matriculados', hi: '§ नामांकित में से' },
    '§ of enrolled children checked in right now': { fr: '§ des enfants inscrits présents en ce moment', es: '§ de los niños matriculados presentes ahora', hi: '§ नामांकित बच्चे अभी उपस्थित' },
    '● § checked in': { fr: '● § présents', es: '● § presentes', hi: '● § उपस्थित' },
    '● § not in': { fr: '● § absents', es: '● § ausentes', hi: '● § अनुपस्थित' },
    '▲ § vs last month': { fr: '▲ § vs mois dernier', es: '▲ § frente al mes pasado', hi: '▲ § पिछले महीने की तुलना में' },
    'Month over month · §': { fr: 'D’un mois à l’autre · §', es: 'Mes a mes · §', hi: 'महीने-दर-महीने · §' },
    'NET ENROLLMENT · § DAYS': { fr: 'INSCRIPTIONS NETTES · § JOURS', es: 'MATRÍCULA NETA · § DÍAS', hi: 'शुद्ध नामांकन · § दिन' },
    'Trailing § days': { fr: '§ derniers jours', es: 'Últimos § días', hi: 'पिछले § दिन' },
    'Trailing § months': { fr: '§ derniers mois', es: 'Últimos § meses', hi: 'पिछले § महीने' },
    'Recent § days': { fr: '§ derniers jours', es: 'Últimos § días', hi: 'पिछले § दिन' },
    'Latest § events': { fr: '§ derniers événements', es: 'Últimos § eventos', hi: 'अंतिम § घटनाएँ' },
    'Last § events across all agencies': { fr: '§ derniers événements de toutes les agences', es: 'Últimos § eventos de todas las agencias', hi: 'सभी एजेंसियों की अंतिम § घटनाएँ' },
    'Page § of § · § events': { fr: 'Page § sur § · § événements', es: 'Página § de § · § eventos', hi: 'पृष्ठ § / § · § घटनाएँ' },
    '§ events': { fr: '§ événements', es: '§ eventos', hi: '§ घटनाएँ' },
    '§ children': { fr: '§ enfants', es: '§ niños', hi: '§ बच्चे' },
    '§ kids': { fr: '§ enfants', es: '§ niños', hi: '§ बच्चे' },
    '§ records': { fr: '§ enregistrements', es: '§ registros', hi: '§ रिकॉर्ड' },
    '§ record': { fr: '§ enregistrement', es: '§ registro', hi: '§ रिकॉर्ड' },
    '§ messages': { fr: '§ messages', es: '§ mensajes', hi: '§ संदेश' },
    '§ messages · § feedback': { fr: '§ messages · § commentaires', es: '§ mensajes · § comentarios', hi: '§ संदेश · § प्रतिक्रिया' },
    '§ messages · § observations viewed': { fr: '§ messages · § observations vues', es: '§ mensajes · § observaciones vistas', hi: '§ संदेश · § अवलोकन देखे गए' },
    '§ observations viewed': { fr: '§ observations vues', es: '§ observaciones vistas', hi: '§ अवलोकन देखे गए' },
    '§ min': { fr: '§ min', es: '§ min', hi: '§ मिनट' },
    '§ months': { fr: '§ mois', es: '§ meses', hi: '§ महीने' },
    '§ total': { fr: '§ au total', es: '§ en total', hi: '§ कुल' },
    '§ selected': { fr: '§ sélectionné(s)', es: '§ seleccionado(s)', hi: '§ चयनित' },
    '§ on waitlist': { fr: '§ sur la liste d’attente', es: '§ en lista de espera', hi: '§ प्रतीक्षा सूची में' },
    '§ centres compliant': { fr: '§ centres conformes', es: '§ centros conformes', hi: '§ केंद्र अनुपालक' },
    '§ ratio breaches': { fr: '§ dépassements de ratio', es: '§ incumplimientos de proporción', hi: '§ अनुपात उल्लंघन' },
    '§ bills past due': { fr: '§ factures en souffrance', es: '§ facturas vencidas', hi: '§ बकाया बिल' },
    '§ centres with children + no staff': { fr: '§ centres avec enfants + sans personnel', es: '§ centros con niños y sin personal', hi: '§ केंद्र बच्चों सहित + स्टाफ़ रहित' },
    'Outstanding receivables: §': { fr: 'Créances en souffrance : §', es: 'Cuentas por cobrar pendientes: §', hi: 'बकाया प्राप्य: §' },
    '§ plan(s). Split a large balance into manageable installments.': { fr: '§ plan(s). Répartissez un solde important en versements gérables.', es: '§ plan(es). Divida un saldo grande en cuotas manejables.', hi: '§ योजना(एँ)। बड़ी राशि को प्रबंधनीय किश्तों में बाँटें।' },
    '§ child(ren) with unusual activity patterns in the last § days.': { fr: '§ enfant(s) présentant des comportements inhabituels au cours des § derniers jours.', es: '§ niño(s) con patrones de actividad inusuales en los últimos § días.', hi: 'पिछले § दिनों में § बच्चे असामान्य गतिविधि पैटर्न के साथ।' },
    '§ responses in the last § months.': { fr: '§ réponses au cours des § derniers mois.', es: '§ respuestas en los últimos § meses.', hi: 'पिछले § महीनों में § प्रतिक्रियाएँ।' },
    '§ active · § expiring in § days · § expired': { fr: '§ actifs · § expirant dans § jours · § expirés', es: '§ activos · § por vencer en § días · § vencidos', hi: '§ सक्रिय · § § दिनों में समाप्त · § समाप्त' },
    '§h ago': { fr: 'il y a §h', es: 'hace §h', hi: '§ घंटे पहले' },
    'active last §h': { fr: 'actif dans les dernières §h', es: 'activo en las últimas §h', hi: 'पिछले § घंटे में सक्रिय' },
    'Used §× • platform': { fr: 'Utilisé §× • plateforme', es: 'Usado §× • plataforma', hi: '§× उपयोग किया • प्लेटफ़ॉर्म' },
    '§-month enrolment trajectory': { fr: 'Trajectoire d’inscription sur § mois', es: 'Trayectoria de matrícula de § meses', hi: '§-महीने का नामांकन प्रक्षेप' },
    'Expired or expiring within § days': { fr: 'Expirés ou expirant dans § jours', es: 'Vencidos o por vencer en § días', hi: '§ दिनों में समाप्त या समाप्त होने वाले' },
    '§ hours across § staff': { fr: '§ heures pour § membres du personnel', es: '§ horas entre § miembros del personal', hi: '§ स्टाफ़ में § घंटे' },
    // 2026-10-22 sweep
    'Showing §–§ of §': { fr: 'Affichage de § à § sur §', es: 'Mostrando §–§ de §', hi: '§–§ दिखाए जा रहे हैं (कुल §)' },
    'Ratio §:§ · Capacity §/§ (§)': { fr: 'Ratio §:§ · Capacité §/§ (§)', es: 'Proporción §:§ · Capacidad §/§ (§)', hi: 'अनुपात §:§ · क्षमता §/§ (§)' },
    'Centres (§)': { fr: 'Centres (§)', es: 'Centros (§)', hi: 'केंद्र (§)' },
    '● § absent': { fr: '● § absents', es: '● § ausentes', hi: '● § अनुपस्थित' },
    '§ absent': { fr: '§ absents', es: '§ ausentes', hi: '§ अनुपस्थित' },
    'Absent: §': { fr: 'Absents : §', es: 'Ausentes: §', hi: 'अनुपस्थित: §' },
    'turns §': { fr: 'aura §', es: 'cumple §', hi: '§ का होगा' },
    'In the last § days': { fr: 'Au cours des § derniers jours', es: 'En los últimos § días', hi: 'पिछले § दिनों में' },
    '§ shift(s) this week': { fr: '§ quart(s) cette semaine', es: '§ turno(s) esta semana', hi: 'इस सप्ताह § शिफ़्ट' },
    '§ agencies · active': { fr: '§ agences · actives', es: '§ agencias · activas', hi: '§ एजेंसियाँ · सक्रिय' },
    '§ children · §/§ educator': { fr: '§ enfants · §/§ éducateur', es: '§ niños · §/§ educador', hi: '§ बच्चे · §/§ शिक्षक' },
    '§ in · § not in yet · § left': { fr: '§ présents · § pas encore arrivés · § partis', es: '§ dentro · § aún sin llegar · § se fueron', hi: '§ अंदर · § अभी नहीं आए · § चले गए' },
    '§ of § submitted': { fr: '§ sur § remis', es: '§ de § enviados', hi: 'जमा: §/§' },
    '§ today': { fr: '§ aujourd’hui', es: '§ hoy', hi: 'आज §' },
    '§ new': { fr: '§ nouveaux', es: '§ nuevos', hi: '§ नए' },
    '§ enrolled today': { fr: '§ inscrits aujourd’hui', es: '§ matriculados hoy', hi: 'आज § नामांकित' },
    '§ things need your attention today': { fr: '§ éléments demandent votre attention aujourd’hui', es: '§ cosas requieren su atención hoy', hi: 'आज § बातों पर आपका ध्यान चाहिए' },
    '§ this month': { fr: '§ ce mois-ci', es: '§ este mes', hi: 'इस महीने §' },
    '§ payments this year': { fr: '§ paiements cette année', es: '§ pagos este año', hi: 'इस वर्ष § भुगतान' },
    '§ filled': { fr: '§ occupé', es: '§ ocupado', hi: '§ भरा' },
    '§ rooms': { fr: '§ salles', es: '§ salas', hi: '§ कक्ष' },
    '§ still in': { fr: '§ encore présents', es: '§ aún dentro', hi: '§ अभी अंदर' },
    '§ went home': { fr: '§ partis à la maison', es: '§ se fueron a casa', hi: '§ घर गए' },
    '§ of § spaces filled right now': { fr: '§ places sur § occupées en ce moment', es: '§ de § plazas ocupadas ahora', hi: 'अभी §/§ स्थान भरे' },
    'No demographic data yet — it fills in as your people complete onboarding (§ of § reported so far).': { fr: 'Aucune donnée démographique pour l’instant — elle se remplit à mesure que votre monde termine son inscription (§ sur § déclarés jusqu’ici).', es: 'Aún no hay datos demográficos: se completan a medida que su gente termina el alta (§ de § informados hasta ahora).', hi: 'अभी कोई जनसांख्यिकी डेटा नहीं — आपके लोग ऑनबोर्डिंग पूरी करते जाएँगे तो यह भरता जाएगा (अब तक §/§ ने बताया)।' },
    'Request #§ sent': { fr: 'Demande n° § envoyée', es: 'Solicitud n.º § enviada', hi: 'अनुरोध #§ भेजा गया' },
    'Open §': { fr: 'Ouverts §', es: 'Abiertos §', hi: 'खुले §' },
    '🔍  Filter § rows…': { fr: '🔍  Filtrer § lignes…', es: '🔍  Filtrar § filas…', hi: '🔍  § पंक्तियाँ फ़िल्टर करें…' },
    '🔍  Filter § items…': { fr: '🔍  Filtrer § éléments…', es: '🔍  Filtrar § elementos…', hi: '🔍  § आइटम फ़िल्टर करें…' },
    'Filter § rows…': { fr: 'Filtrer § lignes…', es: 'Filtrar § filas…', hi: '§ पंक्तियाँ फ़िल्टर करें…' },
    '© § KiddieTrac Inc. All Rights Reserved.': { fr: '© § KiddieTrac Inc. Tous droits réservés.', es: '© § KiddieTrac Inc. Todos los derechos reservados.', hi: '© § KiddieTrac Inc. सर्वाधिकार सुरक्षित।' },
    '© §–§ KiddieTrac. All rights reserved. ·': { fr: '© §–§ KiddieTrac. Tous droits réservés. ·', es: '© §–§ KiddieTrac. Todos los derechos reservados. ·', hi: '© §–§ KiddieTrac. सर्वाधिकार सुरक्षित। ·' },
    '§ min ago': { fr: 'il y a § min', es: 'hace § min', hi: '§ मिनट पहले' },
    '§/mo': { fr: '§/mois', es: '§/mes', hi: '§/माह' }

  };

  // Match a string against a number-glued template: blank the numbers to form a key,
  // look it up, and reinsert the numbers into the localized template in the same order.
  // The SAME number regex the templates were built with; if the template is unknown the
  // string is left untouched (never mangled).
  var I18N_NUM = /\$?\d[\d,]*(?:\.\d+)?%?/g;
  function translatePattern(text, loc) {
    var raw = String(text);
    if (!/\d/.test(raw)) return null;
    var lead = raw.match(/^\s*/)[0], tail = raw.match(/\s*$/)[0];
    var body = raw.slice(lead.length, raw.length - tail.length);
    var nums = body.match(I18N_NUM);
    if (!nums) return null;
    var tpl = body.replace(I18N_NUM, '\u00a7');
    var hit = PATTERNS[tpl];
    if (!hit || !hit[loc]) return null;
    var i = 0;
    var out = hit[loc].replace(/\u00a7/g, function () { return i < nums.length ? nums[i++] : ''; });
    return lead + out + tail;
  }


  // Templates with a name or other free text in them ("Good night, Anthony", "🔴 Sam
  // clocked out"). {1}, {2} stand for the free text and are carried into the translation
  // unchanged. Tried only after the dictionary and the number templates miss. (2026-10-22)
  var NAMED = [
    ['Good morning, {1}', { fr: 'Bonjour, {1}', es: 'Buenos días, {1}', hi: 'सुप्रभात, {1}' }],
    ['Good afternoon, {1}', { fr: 'Bon après-midi, {1}', es: 'Buenas tardes, {1}', hi: 'नमस्कार, {1}' }],
    ['Good evening, {1}', { fr: 'Bonsoir, {1}', es: 'Buenas tardes, {1}', hi: 'शुभ संध्या, {1}' }],
    ['Good night, {1}', { fr: 'Bonne nuit, {1}', es: 'Buenas noches, {1}', hi: 'शुभ रात्रि, {1}' }],
    ['{1} clocked in', { fr: '{1} a pointé son arrivée', es: '{1} fichó la entrada', hi: '{1} ने क्लॉक इन किया' }],
    ['{1} clocked out', { fr: '{1} a pointé son départ', es: '{1} fichó la salida', hi: '{1} ने क्लॉक आउट किया' }],
    ['{1} (Child) checked in', { fr: '{1} (enfant) est arrivé', es: '{1} (niño) entró', hi: '{1} (बच्चा) का चेक-इन हुआ' }],
    ['{1} (Child) checked out', { fr: '{1} (enfant) est parti', es: '{1} (niño) salió', hi: '{1} (बच्चा) का चेक-आउट हुआ' }],
    ['Auto clock-out — {1}', { fr: 'Départ pointé automatiquement — {1}', es: 'Salida fichada automáticamente — {1}', hi: 'स्वतः क्लॉक-आउट — {1}' }],
    ['Repeated missed clock-outs — {1}', { fr: 'Oublis de pointage de départ répétés — {1}', es: 'Salidas sin fichar repetidas — {1}', hi: 'बार-बार छूटे क्लॉक-आउट — {1}' }],
    ['Signed out automatically — {1}', { fr: 'Départ enregistré automatiquement — {1}', es: 'Salida registrada automáticamente — {1}', hi: 'स्वतः साइन आउट — {1}' }],
    ['{1}: no sign-out recorded. Correct the times in Attendance.', { fr: '{1} : aucun départ enregistré. Corrigez les heures dans Présences.', es: '{1}: no se registró la salida. Corrija las horas en Asistencia.', hi: '{1}: कोई साइन-आउट दर्ज नहीं। उपस्थिति में समय सुधारें।' }],
    ['Standing hours · {1}', { fr: 'Heures habituelles · {1}', es: 'Horario fijo · {1}', hi: 'नियमित घंटे · {1}' }],
    ['Autofill {1}', { fr: 'Remplissage automatique {1}', es: 'Relleno automático {1}', hi: 'स्वतः भरें {1}' }],
    ['Report {1} absent today', { fr: 'Signaler une absence aujourd’hui : {1}', es: 'Informar de la ausencia de {1} hoy', hi: 'आज {1} की अनुपस्थिति बताएँ' }],
    ['{1} — not started', { fr: '{1} — non commencé', es: '{1} — sin empezar', hi: '{1} — शुरू नहीं हुआ' }],
    ['About {1}, build and diagnostics', { fr: 'À propos de {1}, version et diagnostics', es: 'Acerca de {1}, versión y diagnóstico', hi: '{1} के बारे में, बिल्ड और निदान' }],
    ['No menu published for the week of {1}.', { fr: 'Aucun menu publié pour la semaine du {1}.', es: 'No se publicó ningún menú para la semana del {1}.', hi: '{1} वाले सप्ताह के लिए कोई मेनू प्रकाशित नहीं।' }],
    ['Nothing logged on {1}.', { fr: 'Rien de consigné le {1}.', es: 'No se registró nada el {1}.', hi: '{1} को कुछ भी दर्ज नहीं।' }],
    ['Welcome to {1}', { fr: 'Bienvenue sur {1}', es: 'Bienvenido a {1}', hi: '{1} में आपका स्वागत है' }],
    ['Welcome to {1}!', { fr: 'Bienvenue sur {1}!', es: '¡Bienvenido a {1}!', hi: '{1} में आपका स्वागत है!' }],
    ['Viewing as {1}', { fr: 'Affiché comme {1}', es: 'Viendo como {1}', hi: '{1} के रूप में देख रहे हैं' }],
    ['No staff clocked in\n\nAssigned here:{1}', { fr: 'Aucun membre du personnel n’a pointé\n\nAffectés ici :{1}', es: 'Nadie del personal ha fichado\n\nAsignados aquí:{1}', hi: 'किसी स्टाफ़ ने क्लॉक इन नहीं किया\n\nयहाँ नियुक्त:{1}' }],
    ['(otherwise the centre’s, {1})', { fr: '(sinon celles du centre, {1})', es: '(si no, el del centro, {1})', hi: '(नहीं तो केंद्र के, {1})' }],
    ['Entered by the parent from the automatic sign-out email — the automatic time was {1}', { fr: 'Saisi par le parent à partir du courriel de départ automatique — l’heure automatique était {1}', es: 'Introducida por el padre desde el correo de salida automática; la hora automática era {1}', hi: 'स्वचालित साइन-आउट ईमेल से अभिभावक द्वारा दर्ज — स्वचालित समय {1} था' }],
    ['Entered by the educator from the automatic sign-out email — the automatic time was {1}', { fr: 'Saisi par l’éducateur à partir du courriel de départ automatique — l’heure automatique était {1}', es: 'Introducida por el educador desde el correo de salida automática; la hora automática era {1}', hi: 'स्वचालित साइन-आउट ईमेल से शिक्षक द्वारा दर्ज — स्वचालित समय {1} था' }],
    ['Uploading {1} files…', { fr: 'Téléversement de {1} fichiers…', es: 'Subiendo {1} archivos…', hi: '{1} फ़ाइलें अपलोड हो रही हैं…' }],
    ['Welcome back to {1}.', { fr: 'Bon retour sur {1}.', es: 'Bienvenido de nuevo a {1}.', hi: '{1} में फिर से स्वागत है।' }],
    ['Good night, {1}!', { fr: 'Bonne nuit, {1}!', es: '¡Buenas noches, {1}!', hi: 'शुभ रात्रि, {1}!' }],
    ['Good morning, {1}!', { fr: 'Bonjour, {1}!', es: '¡Buenos días, {1}!', hi: 'सुप्रभात, {1}!' }],
    ['Good afternoon, {1}!', { fr: 'Bon après-midi, {1}!', es: '¡Buenas tardes, {1}!', hi: 'नमस्कार, {1}!' }],
    ['Good evening, {1}!', { fr: 'Bonsoir, {1}!', es: '¡Buenas tardes, {1}!', hi: 'शुभ संध्या, {1}!' }]
  ];
  var NAMED_RX = NAMED.map(function (n) {
    var rx = n[0].replace(/[.*+?^$()|[\]\\]/g, '\\$&').replace(/\{(\d)\}/g, '([\\s\\S]+?)');
    return [new RegExp('^' + rx + '$'), n[1], (n[0].match(/\{(\d)\}/g) || []).map(function (t) { return +t.charAt(1); })];
  });
  function translateNamed(text, loc) {
    var raw = String(text), body = raw.trim();
    if (!body) return null;
    var lead = raw.match(/^\s*/)[0], tail = raw.match(/\s*$/)[0];
    for (var i = 0; i < NAMED_RX.length; i++) {
      var m = NAMED_RX[i][0].exec(body);
      if (!m || !NAMED_RX[i][1][loc]) continue;
      var vals = {};
      NAMED_RX[i][2].forEach(function (slot, k) { vals[slot] = m[k + 1]; });
      return lead + NAMED_RX[i][1][loc].replace(/\{(\d)\}/g, function (_, d) { return vals[d] != null ? vals[d] : ''; }) + tail;
    }
    return null;
  }

  // "Attendance — Your child's attendance history", "Daily overview · 2026-10-21": translate
  // each side of a " — " / " · " join on its own. Parts that are names or dates stay as
  // they are. Returns null unless at least one part was translated.
  function translateComposite(text, loc) {
    var raw = String(text);
    if (!/ (—|·) /.test(raw)) return null;
    var parts = raw.split(/( — | · )/), any = false;
    for (var i = 0; i < parts.length; i += 2) {
      if (!parts[i] || !/\p{L}/u.test(parts[i])) continue;
      var t = translate(parts[i], loc);
      if (t === null) t = translatePattern(parts[i], loc);
      if (t === null) t = translateNamed(parts[i], loc);
      if (t !== null) { parts[i] = t; any = true; }
    }
    return any ? parts.join('') : null;
  }

  // Every strategy, in order. null when nothing knows the string.
  function translateAny(text, loc) {
    var out = translate(text, loc);
    if (out === null) out = translatePattern(text, loc);
    if (out === null) out = translateNamed(text, loc);
    if (out === null) out = translateComposite(text, loc);
    return out;
  }

  function translate(text, loc) {
    var raw = String(text);
    var trimmed = raw.trim();
    if (!trimmed) return null;
    var lead = raw.match(/^\s*/)[0];
    var tail = raw.match(/\s*$/)[0];

    // 1) Whole-string exact match FIRST — so a key that itself carries a symbol
    //    ("happy 😊", "Mood: —") wins before we start stripping symbols, otherwise the
    //    stripped symbol would be re-added on top and we'd get "feliz 😊 😊".
    var norm = trimmed.replace(/\s+/g, ' ');
    var whole = lookup(trimmed) || (norm !== trimmed ? lookup(norm) : null);
    if (whole && whole[loc]) return lead + whole[loc] + tail;

    // 2) Otherwise strip a LEADING symbol run (emoji: "📢 Announcements") and a TRAILING
    //    symbol run (a comma after "Good evening, ", a "→" on "View billing →"), look up
    //    the core words, and re-attach both.
    var lm = /^([^\p{L}\p{N}]*)([\s\S]*)$/u.exec(trimmed);
    var prefix = lm ? lm[1] : '';
    var core = lm ? lm[2] : trimmed;
    var suffix = '';
    var tm = /^([\s\S]*[\p{L}\p{N}])([^\p{L}\p{N}]*)$/u.exec(core);
    if (tm) { core = tm[1]; suffix = tm[2]; }

    var hit = lookup(core);
    if (!hit) { var cn = core.replace(/\s+/g, ' '); if (cn !== core) hit = lookup(cn); }
    if (!hit || !hit[loc]) return null;

    return lead + prefix + hit[loc] + suffix + tail;
  }

  var done = new WeakSet();     // nodes already translated — repeat sweeps do nothing
  var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1 };

  function walk(root, loc) {
    if (!root) return;
    var it = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (done.has(n)) return NodeFilter.FILTER_REJECT;
        var p = n.parentNode;
        if (!p || SKIP[p.nodeName]) return NodeFilter.FILTER_REJECT;
        if (p.closest && p.closest('[data-no-i18n], input, textarea')) return NodeFilter.FILTER_REJECT;
        return n.nodeValue && n.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      }
    });
    var n, hits = [];
    while ((n = it.nextNode())) hits.push(n);
    for (var i = 0; i < hits.length; i++) {
      var out = translateAny(hits[i].nodeValue, loc);
      done.add(hits[i]);                    // mark either way: never reconsider this node
      if (out !== null && out !== hits[i].nodeValue) hits[i].nodeValue = out;
    }

    // Placeholders, titles and aria-labels.
    var els = root.querySelectorAll ? root.querySelectorAll('[placeholder], [title], [aria-label]') : [];
    for (var j = 0; j < els.length; j++) {
      var el = els[j];
      if (done.has(el)) continue;
      done.add(el);
      ['placeholder', 'title', 'aria-label'].forEach(function (a) {
        var v = el.getAttribute(a);
        if (!v) return;
        var t = translateAny(v, loc);
        if (t !== null && t !== v) el.setAttribute(a, t);
      });
    }
  }


  // On a page WITHOUT the app top bar (the login / index page), there is no language
  // control at all — a non-English visitor cannot even change it before signing in. Inject
  // a compact picker in that case. It sets kt_locale locally and reloads so the page
  // re-renders in the new language; the server preference syncs after login.
  // Persist the chosen language across logins and devices. The picker writes the choice
  // to the server (users.locale) AND to localStorage; on a FRESH login (new browser /
  // device) localStorage is empty, so seed it from the signed-in user's saved locale.
  // Local choice, once present, wins — so a mid-session switch is never overridden.
  function seedLocaleFromUser() {
    try {
      if (localStorage.getItem('kt_locale')) return;
      var u = JSON.parse(sessionStorage.getItem('kt_user') || localStorage.getItem('kt_user') || '{}');
      if (u && u.locale && LOCALES.indexOf(u.locale) !== -1) localStorage.setItem('kt_locale', u.locale);
    } catch (e) {}
  }

  /* Pre-account pages (enroll, set-password) opt in with <body data-kt-lang-picker>:
     a new parent has no account to save a language on yet, so they need a menu here.
     The sign-in page still has none (language is set at onboarding / in Settings). */
  function injectPagePicker() {
    try {
      if (!document.body || !document.body.hasAttribute('data-kt-lang-picker')) return;
      if (document.getElementById('kt-page-locale')) return;
      var wrap = document.createElement('div');
      wrap.style.cssText = 'position:fixed;top:12px;right:14px;z-index:2147480000;';
      var sel = document.createElement('select');
      sel.id = 'kt-page-locale';
      sel.setAttribute('aria-label', 'Language');
      sel.style.cssText = 'border:1px solid rgba(15,23,42,.18);border-radius:8px;background:rgba(255,255,255,.95);'
        + 'padding:6px 10px;font-size:13px;font-weight:600;color:#334155;cursor:pointer;box-shadow:0 1px 3px rgba(16,40,64,.12);';
      [['en', '🌐 English'], ['fr', '🌐 Français'], ['es', '🌐 Español'], ['hi', '🌐 हिन्दी']].forEach(function (o) {
        var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1];
        if (o[0] === locale()) op.selected = true;
        sel.appendChild(op);
      });
      sel.setAttribute('data-kt-no-translate', '1');
      sel.addEventListener('change', function () {
        try { localStorage.setItem('kt_locale', sel.value); } catch (e) {}
        window.location.reload();
      });
      wrap.appendChild(sel);
      document.body.appendChild(wrap);
    } catch (e) {}
  }

  function injectLoginPicker() { injectPagePicker(); return; /* removed: language set at onboarding/settings */
    try {
      if (document.getElementById('kt-tb-locale')) return;      // the app top bar owns it
      if (document.getElementById('kt-login-locale')) return;   // already injected
      if (!document.getElementById('loginForm')) return;        // only on the login page
      var wrap = document.createElement('div');
      wrap.style.cssText = 'position:fixed;top:14px;right:16px;z-index:2147480000;';
      var sel = document.createElement('select');
      sel.id = 'kt-login-locale';
      sel.setAttribute('aria-label', 'Language');
      sel.style.cssText = 'border:1px solid rgba(15,23,42,.18);border-radius:8px;background:rgba(255,255,255,.92);'
        + 'padding:6px 10px;font-size:13px;font-weight:600;color:#334155;cursor:pointer;'
        + 'box-shadow:0 1px 3px rgba(16,40,64,.12);';
      var opts = [['en', '🌐 English'], ['fr', '🌐 Français'], ['es', '🌐 Español'], ['hi', '🌐 हिन्दी']];
      var cur = locale();
      for (var i = 0; i < opts.length; i++) {
        var o = document.createElement('option');
        o.value = opts[i][0]; o.textContent = opts[i][1];
        if (opts[i][0] === cur) o.selected = true;
        sel.appendChild(o);
      }
      sel.addEventListener('change', function () {
        try { localStorage.setItem('kt_locale', sel.value); } catch (e) {}
        window.location.reload();
      });
      wrap.appendChild(sel);
      document.body.appendChild(wrap);
    } catch (e) {}
  }

  function apply() {
    seedLocaleFromUser();
    injectLoginPicker();
    var loc = locale();
    if (loc === 'en') return;               // nothing to do
    loadPack(loc);
    try { walk(document.body, loc); } catch (e) {}
  }

  // ── Dates and times ────────────────────────────────────────────────────────
  //
  // Dates are formatted at ~60 call sites, variously as toLocaleDateString('en-CA'),
  // toLocaleDateString(undefined), toLocaleTimeString([]) and new Intl.DateTimeFormat(
  // 'en-CA'). Rather than chase every one, hand them the active locale centrally: when a
  // call asks for no locale, or explicitly for English, it gets the language the user has
  // chosen instead.
  //
  // ONLY Date formatting is touched. Number.prototype.toLocaleString is left alone, so
  // money and counts keep formatting exactly as before.
  var BCP47 = { en: 'en-CA', fr: 'fr-CA', es: 'es-ES', hi: 'hi-IN' };
  function dateTag() { return BCP47[locale()] || 'en-CA'; }

  function wantsDefault(loc) {
    if (loc === undefined || loc === null) return true;
    if (Array.isArray(loc) && !loc.length) return true;
    var one = Array.isArray(loc) ? loc[0] : loc;
    return typeof one === 'string' && /^en\b/i.test(one);   // hard-coded English
  }
  function pick(loc) { return wantsDefault(loc) ? dateTag() : loc; }

  /* US AGENCIES READ "3:27 PM" (2026-10-28, Brightwood / California). English always meant en-CA here, so every
     time in a US agency said "3:27 p.m.". For a US agency (kt-term.js -> kt_spelling = 'us') a displayed 12-hour
     TIME is formatted en-US. Never a 24-hour request (hour12:false / hourCycle — time inputs) and never a numeric
     2-digit date (en-CA's YYYY-MM-DD is used as a key in places), so nothing that is data changes shape. */
  function usTime(opts) {
    try { if (locale() !== 'en' || sessionStorage.getItem('kt_spelling') !== 'us') return false; } catch (e) { return false; }
    opts = opts || {};
    if (opts.hour12 === false || opts.hourCycle) return false;
    if (opts.month === '2-digit' || opts.day === '2-digit') return false;
    return true;
  }

  ['toLocaleDateString', 'toLocaleTimeString', 'toLocaleString'].forEach(function (fn) {
    var orig = Date.prototype[fn];
    Date.prototype[fn] = function (loc, opts) {
      if (fn === 'toLocaleTimeString' && wantsDefault(loc) && usTime(opts)) return orig.call(this, 'en-US', opts);
      return orig.call(this, pick(loc), opts);
    };
  });

  if (window.Intl && Intl.DateTimeFormat) {
    var OrigDTF = Intl.DateTimeFormat;
    var Patched = function (loc, opts) {
      if (opts && (opts.hour || opts.timeStyle) && wantsDefault(loc) && usTime(opts)) return new OrigDTF('en-US', opts);
      return new OrigDTF(pick(loc), opts);   // works with and without `new`
    };
    Patched.supportedLocalesOf = OrigDTF.supportedLocalesOf.bind(OrigDTF);
    Patched.prototype = OrigDTF.prototype;
    Intl.DateTimeFormat = Patched;
  }

  window.KT = window.KT || {};
  window.KT.i18n = {
    apply: apply,
    locale: locale,
    locales: LOCALES,
    t: function (s) { var out = translateAny(s, locale()); return out === null ? s : out; },
    packVersion: PACK_V,
    dict: DICT
  };

  // Screens render asynchronously, so sweep on a short poll as well as on navigation.
  // Idempotent by construction (the WeakSet), so a sweep with nothing new to do makes no
  // DOM changes at all — important, because screens that re-render on mutation would
  // otherwise fight us.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
  else apply();
  window.addEventListener('hashchange', function () { setTimeout(apply, 60); });
  (window.KT && KT.sweepBus) ? KT.sweepBus.on(apply) : setInterval(apply, 2500);
})(window);
