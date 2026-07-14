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
  var DICT = {
    // ── Top bar / shell ──
    'Good morning': { fr: 'Bonjour', es: 'Buenos días', hi: 'सुप्रभात' },
    'Good afternoon': { fr: 'Bon après-midi', es: 'Buenas tardes', hi: 'नमस्कार' },
    'Good evening': { fr: 'Bonsoir', es: 'Buenas noches', hi: 'शुभ संध्या' },
    'View as': { fr: 'Afficher en tant que', es: 'Ver como', hi: 'इस रूप में देखें' },
    'Super admin': { fr: 'Super administrateur', es: 'Superadministrador', hi: 'सुपर एडमिन' },
    'Super admin (default)': { fr: 'Super administrateur (défaut)', es: 'Superadministrador (predeterminado)', hi: 'सुपर एडमिन (डिफ़ॉल्ट)' },
    'Platform Admin': { fr: 'Administrateur de la plateforme', es: 'Administrador de la plataforma', hi: 'प्लेटफ़ॉर्म एडमिन' },
    'Agency admin': { fr: 'Administrateur de l’agence', es: 'Administrador de la agencia', hi: 'एजेंसी एडमिन' },
    'Centre director': { fr: 'Directeur du centre', es: 'Director del centro', hi: 'केंद्र निदेशक' },
    'Educator': { fr: 'Éducateur', es: 'Educador', hi: 'शिक्षक' },
    'Educators': { fr: 'Éducateurs', es: 'Educadores', hi: 'शिक्षक' },
    'Parent / guardian': { fr: 'Parent / tuteur', es: 'Padre / tutor', hi: 'अभिभावक' },
    'Auditor': { fr: 'Auditeur', es: 'Auditor', hi: 'ऑडिटर' },
    'Directors': { fr: 'Directeurs', es: 'Directores', hi: 'निदेशक' },
    'Parents': { fr: 'Parents', es: 'Padres', hi: 'अभिभावक' },
    'Admins': { fr: 'Administrateurs', es: 'Administradores', hi: 'एडमिन' },
    'Home': { fr: 'Accueil', es: 'Inicio', hi: 'होम' },
    'Quick add': { fr: 'Ajout rapide', es: 'Añadir rápido', hi: 'त्वरित जोड़ें' },
    'QUICK ADD': { fr: 'AJOUT RAPIDE', es: 'AÑADIR RÁPIDO', hi: 'त्वरित जोड़ें' },
    'Search...': { fr: 'Rechercher…', es: 'Buscar…', hi: 'खोजें…' },
    'Search…': { fr: 'Rechercher…', es: 'Buscar…', hi: 'खोजें…' },
    'Collapse all': { fr: 'Tout réduire', es: 'Contraer todo', hi: 'सब समेटें' },
    'Sign out': { fr: 'Se déconnecter', es: 'Cerrar sesión', hi: 'साइन आउट' },
    'Settings': { fr: 'Paramètres', es: 'Ajustes', hi: 'सेटिंग्स' },
    'All agencies': { fr: 'Toutes les agences', es: 'Todas las agencias', hi: 'सभी एजेंसियाँ' },
    'Language': { fr: 'Langue', es: 'Idioma', hi: 'भाषा' },
    'AI assistant': { fr: 'Assistant IA', es: 'Asistente de IA', hi: 'एआई सहायक' },

    // ── Sidebar sections ──
    'PLATFORM': { fr: 'PLATEFORME', es: 'PLATAFORMA', hi: 'प्लेटफ़ॉर्म' },
    'OVERVIEW': { fr: 'APERÇU', es: 'RESUMEN', hi: 'अवलोकन' },
    'OPERATIONS': { fr: 'OPÉRATIONS', es: 'OPERACIONES', hi: 'संचालन' },
    'ENGAGEMENT': { fr: 'ENGAGEMENT', es: 'PARTICIPACIÓN', hi: 'सहभागिता' },
    'ADMINISTRATION': { fr: 'ADMINISTRATION', es: 'ADMINISTRACIÓN', hi: 'प्रशासन' },
    'BILLING': { fr: 'FACTURATION', es: 'FACTURACIÓN', hi: 'बिलिंग' },
    'COMPLIANCE': { fr: 'CONFORMITÉ', es: 'CUMPLIMIENTO', hi: 'अनुपालन' },
    'STAFF': { fr: 'PERSONNEL', es: 'PERSONAL', hi: 'स्टाफ़' },
    'GROWTH': { fr: 'CROISSANCE', es: 'CRECIMIENTO', hi: 'विकास' },
    'SETTINGS': { fr: 'PARAMÈTRES', es: 'AJUSTES', hi: 'सेटिंग्स' },

    // ── Navigation labels ──
    'Platform overview': { fr: 'Aperçu de la plateforme', es: 'Resumen de la plataforma', hi: 'प्लेटफ़ॉर्म अवलोकन' },
    'Agency overview': { fr: 'Aperçu de l’agence', es: 'Resumen de la agencia', hi: 'एजेंसी अवलोकन' },
    'Dashboard': { fr: 'Tableau de bord', es: 'Panel', hi: 'डैशबोर्ड' },
    'Provider map': { fr: 'Carte des prestataires', es: 'Mapa de proveedores', hi: 'प्रदाता मानचित्र' },
    'Daily log': { fr: 'Journal quotidien', es: 'Registro diario', hi: 'दैनिक लॉग' },
    'Messages': { fr: 'Messages', es: 'Mensajes', hi: 'संदेश' },
    'Announcements': { fr: 'Annonces', es: 'Anuncios', hi: 'घोषणाएँ' },
    'Lesson plans': { fr: 'Plans de leçon', es: 'Planes de clase', hi: 'पाठ योजनाएँ' },
    'AI Lesson Plans': { fr: 'Plans de leçon IA', es: 'Planes de clase con IA', hi: 'एआई पाठ योजनाएँ' },
    'Observations': { fr: 'Observations', es: 'Observaciones', hi: 'अवलोकन' },
    'Learning Observations': { fr: 'Observations d’apprentissage', es: 'Observaciones de aprendizaje', hi: 'सीखने के अवलोकन' },
    'Schedule': { fr: 'Horaire', es: 'Horario', hi: 'शेड्यूल' },
    'Certifications': { fr: 'Certifications', es: 'Certificaciones', hi: 'प्रमाणपत्र' },
    'Timesheets': { fr: 'Feuilles de temps', es: 'Hojas de horas', hi: 'टाइमशीट' },
    'Waitlist': { fr: 'Liste d’attente', es: 'Lista de espera', hi: 'प्रतीक्षा सूची' },
    'Incidents': { fr: 'Incidents', es: 'Incidentes', hi: 'घटनाएँ' },
    'Medications': { fr: 'Médicaments', es: 'Medicamentos', hi: 'दवाइयाँ' },
    'Health': { fr: 'Santé', es: 'Salud', hi: 'स्वास्थ्य' },
    'Immunizations': { fr: 'Vaccinations', es: 'Vacunas', hi: 'टीकाकरण' },
    'Immunization due': { fr: 'Vaccination à faire', es: 'Vacuna pendiente', hi: 'टीकाकरण देय' },
    'Allergy alerts': { fr: 'Alertes allergies', es: 'Alertas de alergia', hi: 'एलर्जी अलर्ट' },
    'Room ratios': { fr: 'Ratios par salle', es: 'Proporciones por sala', hi: 'कक्ष अनुपात' },
    'Room rotations': { fr: 'Rotations des salles', es: 'Rotaciones de salas', hi: 'कक्ष रोटेशन' },
    'Closures': { fr: 'Fermetures', es: 'Cierres', hi: 'बंद दिवस' },
    'Late pickups': { fr: 'Retards de départ', es: 'Recogidas tardías', hi: 'देर से पिकअप' },
    'Field trips': { fr: 'Sorties éducatives', es: 'Excursiones', hi: 'फ़ील्ड ट्रिप' },
    'Field trip GPS': { fr: 'GPS des sorties', es: 'GPS de excursiones', hi: 'फ़ील्ड ट्रिप जीपीएस' },
    'Bus routes': { fr: 'Circuits d’autobus', es: 'Rutas de autobús', hi: 'बस मार्ग' },
    'Activity zones': { fr: 'Zones d’activité', es: 'Zonas de actividad', hi: 'गतिविधि क्षेत्र' },
    'Weekly menu': { fr: 'Menu de la semaine', es: 'Menú semanal', hi: 'साप्ताहिक मेन्यू' },
    'CACFP meals': { fr: 'Repas CACFP', es: 'Comidas CACFP', hi: 'CACFP भोजन' },
    'Attendance': { fr: 'Présences', es: 'Asistencia', hi: 'उपस्थिति' },
    'Attendance days': { fr: 'Jours de présence', es: 'Días de asistencia', hi: 'उपस्थिति दिवस' },
    'Photos': { fr: 'Photos', es: 'Fotos', hi: 'तस्वीरें' },
    'Video feed': { fr: 'Fil vidéo', es: 'Vídeos', hi: 'वीडियो फ़ीड' },
    'Photo AI tagging': { fr: 'Étiquetage photo IA', es: 'Etiquetado de fotos con IA', hi: 'एआई फ़ोटो टैगिंग' },
    'Conferences': { fr: 'Rencontres', es: 'Conferencias', hi: 'बैठकें' },
    'Report cards': { fr: 'Bulletins', es: 'Boletines', hi: 'रिपोर्ट कार्ड' },
    'Wellness digest': { fr: 'Bilan bien-être', es: 'Resumen de bienestar', hi: 'कल्याण सारांश' },
    'Curriculum': { fr: 'Programme', es: 'Currículo', hi: 'पाठ्यक्रम' },
    'Curriculum library': { fr: 'Bibliothèque de programmes', es: 'Biblioteca de currículo', hi: 'पाठ्यक्रम पुस्तकालय' },
    'HDLH gaps': { fr: 'Écarts HDLH', es: 'Brechas HDLH', hi: 'HDLH अंतराल' },
    'Calendar': { fr: 'Calendrier', es: 'Calendario', hi: 'कैलेंडर' },
    'Time off': { fr: 'Congés', es: 'Tiempo libre', hi: 'छुट्टी' },
    'Time off requests': { fr: 'Demandes de congé', es: 'Solicitudes de tiempo libre', hi: 'छुट्टी अनुरोध' },
    'Background checks': { fr: 'Vérifications d’antécédents', es: 'Verificación de antecedentes', hi: 'पृष्ठभूमि जाँच' },
    'Payroll': { fr: 'Paie', es: 'Nómina', hi: 'वेतन' },
    'Substitutes': { fr: 'Remplaçants', es: 'Suplentes', hi: 'स्थानापन्न' },
    'Clock in/out': { fr: 'Pointage', es: 'Fichar', hi: 'क्लॉक इन/आउट' },
    'Time clock': { fr: 'Pointeuse', es: 'Reloj de fichaje', hi: 'टाइम क्लॉक' },
    'Inspection checklist': { fr: 'Liste d’inspection', es: 'Lista de inspección', hi: 'निरीक्षण सूची' },
    'Renewals calendar': { fr: 'Calendrier des renouvellements', es: 'Calendario de renovaciones', hi: 'नवीनीकरण कैलेंडर' },
    'CWELCC subsidies': { fr: 'Subventions CWELCC', es: 'Subsidios CWELCC', hi: 'CWELCC सब्सिडी' },
    'Retention': { fr: 'Rétention', es: 'Retención', hi: 'प्रतिधारण' },
    'Anomalies': { fr: 'Anomalies', es: 'Anomalías', hi: 'विसंगतियाँ' },
    'MRR dashboard': { fr: 'Tableau de bord MRR', es: 'Panel de MRR', hi: 'MRR डैशबोर्ड' },
    'Feature flags': { fr: 'Indicateurs de fonctionnalité', es: 'Indicadores de función', hi: 'फ़ीचर फ़्लैग' },
    'Branding': { fr: 'Image de marque', es: 'Marca', hi: 'ब्रांडिंग' },
    'AI digest status': { fr: 'État des résumés IA', es: 'Estado de resúmenes de IA', hi: 'एआई सारांश स्थिति' },
    'Website': { fr: 'Site web', es: 'Sitio web', hi: 'वेबसाइट' },
    'Invitation codes': { fr: 'Codes d’invitation', es: 'Códigos de invitación', hi: 'आमंत्रण कोड' },
    'eDocuments': { fr: 'Documents électroniques', es: 'Documentos electrónicos', hi: 'ई-दस्तावेज़' },
    'Expenses': { fr: 'Dépenses', es: 'Gastos', hi: 'व्यय' },
    'User management': { fr: 'Gestion des utilisateurs', es: 'Gestión de usuarios', hi: 'उपयोगकर्ता प्रबंधन' },
    'Centres / Rooms': { fr: 'Centres / Salles', es: 'Centros / Salas', hi: 'केंद्र / कक्ष' },
    'Centres': { fr: 'Centres', es: 'Centros', hi: 'केंद्र' },
    'Children': { fr: 'Enfants', es: 'Niños', hi: 'बच्चे' },
    'Families': { fr: 'Familles', es: 'Familias', hi: 'परिवार' },
    'Roles & permissions': { fr: 'Rôles et permissions', es: 'Roles y permisos', hi: 'भूमिकाएँ और अनुमतियाँ' },
    'Custom forms': { fr: 'Formulaires personnalisés', es: 'Formularios personalizados', hi: 'कस्टम फ़ॉर्म' },
    'Forms': { fr: 'Formulaires', es: 'Formularios', hi: 'फ़ॉर्म' },
    'Compliance': { fr: 'Conformité', es: 'Cumplimiento', hi: 'अनुपालन' },
    'Audit log': { fr: 'Journal d’audit', es: 'Registro de auditoría', hi: 'ऑडिट लॉग' },
    'Audit logs': { fr: 'Journaux d’audit', es: 'Registros de auditoría', hi: 'ऑडिट लॉग' },
    'Security alerts': { fr: 'Alertes de sécurité', es: 'Alertas de seguridad', hi: 'सुरक्षा अलर्ट' },
    'Data retention & compliance': { fr: 'Conservation des données et conformité', es: 'Retención de datos y cumplimiento', hi: 'डेटा प्रतिधारण और अनुपालन' },
    'Billing (Stripe)': { fr: 'Facturation (Stripe)', es: 'Facturación (Stripe)', hi: 'बिलिंग (Stripe)' },
    'Billing schedule': { fr: 'Calendrier de facturation', es: 'Calendario de facturación', hi: 'बिलिंग शेड्यूल' },
    'Billing reminders': { fr: 'Rappels de facturation', es: 'Recordatorios de facturación', hi: 'बिलिंग अनुस्मारक' },
    'Bulk invoice run': { fr: 'Facturation en lot', es: 'Facturación masiva', hi: 'सामूहिक चालान' },
    'Refunds': { fr: 'Remboursements', es: 'Reembolsos', hi: 'रिफ़ंड' },
    'Payment plans': { fr: 'Plans de paiement', es: 'Planes de pago', hi: 'भुगतान योजनाएँ' },
    'Tuition plans': { fr: 'Forfaits de frais', es: 'Planes de matrícula', hi: 'शुल्क योजनाएँ' },
    'Tuition increases': { fr: 'Hausses de frais', es: 'Aumentos de matrícula', hi: 'शुल्क वृद्धि' },
    'Sibling discounts': { fr: 'Rabais fratrie', es: 'Descuentos para hermanos', hi: 'सहोदर छूट' },
    'Vacation holds': { fr: 'Places réservées (vacances)', es: 'Reservas por vacaciones', hi: 'अवकाश होल्ड' },
    'QuickBooks (Intuit)': { fr: 'QuickBooks (Intuit)', es: 'QuickBooks (Intuit)', hi: 'QuickBooks (Intuit)' },
    'Enrolment forecast': { fr: 'Prévision des inscriptions', es: 'Previsión de matrícula', hi: 'नामांकन पूर्वानुमान' },
    'Reports': { fr: 'Rapports', es: 'Informes', hi: 'रिपोर्ट' },
    'Re-enrollment': { fr: 'Réinscription', es: 'Rematriculación', hi: 'पुनः नामांकन' },
    'Engagement score': { fr: 'Score d’engagement', es: 'Puntuación de participación', hi: 'सहभागिता स्कोर' },
    'NPS': { fr: 'NPS', es: 'NPS', hi: 'NPS' },
    'Churn risk': { fr: 'Risque d’attrition', es: 'Riesgo de abandono', hi: 'ग्राहक-हानि जोखिम' },
    'AI doc extract': { fr: 'Extraction de documents IA', es: 'Extracción de documentos con IA', hi: 'एआई दस्तावेज़ निष्कर्षण' },
    'Marketing': { fr: 'Marketing', es: 'Marketing', hi: 'मार्केटिंग' },
    'Drip campaigns': { fr: 'Campagnes automatisées', es: 'Campañas automatizadas', hi: 'ड्रिप अभियान' },
    'SMS broadcast': { fr: 'Diffusion SMS', es: 'Difusión por SMS', hi: 'एसएमएस प्रसारण' },
    'Email settings': { fr: 'Paramètres de messagerie', es: 'Ajustes de correo', hi: 'ईमेल सेटिंग्स' },
    'Notifications': { fr: 'Notifications', es: 'Notificaciones', hi: 'सूचनाएँ' },
    'Support tickets': { fr: 'Tickets d’assistance', es: 'Tickets de soporte', hi: 'सहायता टिकट' },
    'Sign-in methods': { fr: 'Méthodes de connexion', es: 'Métodos de inicio de sesión', hi: 'साइन-इन विधियाँ' },
    'Two-factor': { fr: 'Double authentification', es: 'Doble factor', hi: 'दो-कारक' },
    'Two-factor (MFA)': { fr: 'Double authentification (MFA)', es: 'Doble factor (MFA)', hi: 'दो-कारक (MFA)' },
    'Help': { fr: 'Aide', es: 'Ayuda', hi: 'सहायता' },
    'Help & guides': { fr: 'Aide et guides', es: 'Ayuda y guías', hi: 'सहायता और गाइड' },
    'Doc workflows': { fr: 'Flux de documents', es: 'Flujos de documentos', hi: 'दस्तावेज़ प्रवाह' },
    'Signed documents': { fr: 'Documents signés', es: 'Documentos firmados', hi: 'हस्ताक्षरित दस्तावेज़' },
    'Tours': { fr: 'Visites', es: 'Visitas', hi: 'दौरे' },
    'Agencies': { fr: 'Agences', es: 'Agencias', hi: 'एजेंसियाँ' },

    // ── Buttons / actions ──
    'Save': { fr: 'Enregistrer', es: 'Guardar', hi: 'सहेजें' },
    'Save changes': { fr: 'Enregistrer les modifications', es: 'Guardar cambios', hi: 'परिवर्तन सहेजें' },
    'Cancel': { fr: 'Annuler', es: 'Cancelar', hi: 'रद्द करें' },
    'Delete': { fr: 'Supprimer', es: 'Eliminar', hi: 'हटाएँ' },
    'Edit': { fr: 'Modifier', es: 'Editar', hi: 'संपादित करें' },
    'Close': { fr: 'Fermer', es: 'Cerrar', hi: 'बंद करें' },
    'Clear': { fr: 'Effacer', es: 'Limpiar', hi: 'साफ़ करें' },
    'Refresh': { fr: 'Actualiser', es: 'Actualizar', hi: 'रिफ़्रेश' },
    'Export': { fr: 'Exporter', es: 'Exportar', hi: 'निर्यात' },
    'Print': { fr: 'Imprimer', es: 'Imprimir', hi: 'प्रिंट' },
    'Send': { fr: 'Envoyer', es: 'Enviar', hi: 'भेजें' },
    'Send broadcast': { fr: 'Envoyer la diffusion', es: 'Enviar difusión', hi: 'प्रसारण भेजें' },
    'Use template': { fr: 'Utiliser le modèle', es: 'Usar plantilla', hi: 'टेम्पलेट उपयोग करें' },
    'Add': { fr: 'Ajouter', es: 'Añadir', hi: 'जोड़ें' },
    'Approve': { fr: 'Approuver', es: 'Aprobar', hi: 'स्वीकृत करें' },
    'Decline': { fr: 'Refuser', es: 'Rechazar', hi: 'अस्वीकार करें' },
    'Confirm': { fr: 'Confirmer', es: 'Confirmar', hi: 'पुष्टि करें' },
    'Back': { fr: 'Retour', es: 'Atrás', hi: 'वापस' },
    'Next': { fr: 'Suivant', es: 'Siguiente', hi: 'अगला' },
    'Take the tour': { fr: 'Faire la visite', es: 'Hacer el recorrido', hi: 'टूर लें' },
    'Ask AI': { fr: 'Demander à l’IA', es: 'Preguntar a la IA', hi: 'एआई से पूछें' },
    '+ Add centre': { fr: '+ Ajouter un centre', es: '+ Añadir centro', hi: '+ केंद्र जोड़ें' },
    '+ Add widget': { fr: '+ Ajouter un widget', es: '+ Añadir widget', hi: '+ विजेट जोड़ें' },
    '+ Invite user': { fr: '+ Inviter un utilisateur', es: '+ Invitar usuario', hi: '+ उपयोगकर्ता आमंत्रित करें' },
    '+ New chat': { fr: '+ Nouvelle conversation', es: '+ Nuevo chat', hi: '+ नई चैट' },
    '+ New Announcement': { fr: '+ Nouvelle annonce', es: '+ Nuevo anuncio', hi: '+ नई घोषणा' },
    '+ New medication': { fr: '+ Nouveau médicament', es: '+ Nuevo medicamento', hi: '+ नई दवा' },
    '+ New observation': { fr: '+ Nouvelle observation', es: '+ Nueva observación', hi: '+ नया अवलोकन' },
    '+ New payment plan': { fr: '+ Nouveau plan de paiement', es: '+ Nuevo plan de pago', hi: '+ नई भुगतान योजना' },
    '+ New report': { fr: '+ Nouveau rapport', es: '+ Nuevo informe', hi: '+ नई रिपोर्ट' },
    '+ New template': { fr: '+ Nouveau modèle', es: '+ Nueva plantilla', hi: '+ नया टेम्पलेट' },
    '+ Raise ticket': { fr: '+ Ouvrir un ticket', es: '+ Abrir ticket', hi: '+ टिकट खोलें' },
    'Edit agency': { fr: 'Modifier l’agence', es: 'Editar agencia', hi: 'एजेंसी संपादित करें' },
    'Create family': { fr: 'Créer une famille', es: 'Crear familia', hi: 'परिवार बनाएँ' },

    // ── Table headers / filters ──
    'Name': { fr: 'Nom', es: 'Nombre', hi: 'नाम' },
    'Status': { fr: 'Statut', es: 'Estado', hi: 'स्थिति' },
    'Date': { fr: 'Date', es: 'Fecha', hi: 'तारीख़' },
    'Actions': { fr: 'Actions', es: 'Acciones', hi: 'कार्रवाइयाँ' },
    'Action': { fr: 'Action', es: 'Acción', hi: 'कार्रवाई' },
    'Actor': { fr: 'Auteur', es: 'Autor', hi: 'कर्ता' },
    'Entity': { fr: 'Entité', es: 'Entidad', hi: 'इकाई' },
    'Child': { fr: 'Enfant', es: 'Niño', hi: 'बच्चा' },
    'Family': { fr: 'Famille', es: 'Familia', hi: 'परिवार' },
    'Staff': { fr: 'Personnel', es: 'Personal', hi: 'स्टाफ़' },
    'Email': { fr: 'Courriel', es: 'Correo', hi: 'ईमेल' },
    'Phone': { fr: 'Téléphone', es: 'Teléfono', hi: 'फ़ोन' },
    'Role': { fr: 'Rôle', es: 'Rol', hi: 'भूमिका' },
    'Centre': { fr: 'Centre', es: 'Centro', hi: 'केंद्र' },
    'Room': { fr: 'Salle', es: 'Sala', hi: 'कक्ष' },
    'Amount': { fr: 'Montant', es: 'Importe', hi: 'राशि' },
    'Category': { fr: 'Catégorie', es: 'Categoría', hi: 'श्रेणी' },
    'Created': { fr: 'Créé', es: 'Creado', hi: 'बनाया गया' },
    'Expires': { fr: 'Expire', es: 'Caduca', hi: 'समाप्ति' },
    'Issued': { fr: 'Délivré', es: 'Emitido', hi: 'जारी' },
    'Cert': { fr: 'Certificat', es: 'Certificado', hi: 'प्रमाणपत्र' },
    'Sent': { fr: 'Envoyé', es: 'Enviado', hi: 'भेजा गया' },
    'To': { fr: 'À', es: 'Para', hi: 'को' },
    'From': { fr: 'De', es: 'De', hi: 'से' },
    'Message': { fr: 'Message', es: 'Mensaje', hi: 'संदेश' },
    'Audience': { fr: 'Destinataires', es: 'Audiencia', hi: 'दर्शक' },
    'Last login': { fr: 'Dernière connexion', es: 'Último acceso', hi: 'अंतिम लॉगिन' },
    'Location': { fr: 'Lieu', es: 'Ubicación', hi: 'स्थान' },
    'Notes': { fr: 'Notes', es: 'Notas', hi: 'टिप्पणियाँ' },
    'All': { fr: 'Tous', es: 'Todos', hi: 'सभी' },
    'All centres': { fr: 'Tous les centres', es: 'Todos los centros', hi: 'सभी केंद्र' },
    'All statuses': { fr: 'Tous les statuts', es: 'Todos los estados', hi: 'सभी स्थितियाँ' },
    'By role': { fr: 'Par rôle', es: 'Por rol', hi: 'भूमिका के अनुसार' },
    'By centre': { fr: 'Par centre', es: 'Por centro', hi: 'केंद्र के अनुसार' },
    'Whole agency': { fr: 'Toute l’agence', es: 'Toda la agencia', hi: 'पूरी एजेंसी' },
    'Sort…': { fr: 'Trier…', es: 'Ordenar…', hi: 'क्रमबद्ध करें…' },
    'Sort...': { fr: 'Trier…', es: 'Ordenar…', hi: 'क्रमबद्ध करें…' },
    'records': { fr: 'enregistrements', es: 'registros', hi: 'रिकॉर्ड' },
    'record': { fr: 'enregistrement', es: 'registro', hi: 'रिकॉर्ड' },
    'Recent broadcasts': { fr: 'Diffusions récentes', es: 'Difusiones recientes', hi: 'हाल के प्रसारण' },

    // ── Statuses ──
    'Active': { fr: 'Actif', es: 'Activo', hi: 'सक्रिय' },
    'Inactive': { fr: 'Inactif', es: 'Inactivo', hi: 'निष्क्रिय' },
    'Enrolled': { fr: 'Inscrit', es: 'Matriculado', hi: 'नामांकित' },
    'Pending': { fr: 'En attente', es: 'Pendiente', hi: 'लंबित' },
    'Draft': { fr: 'Brouillon', es: 'Borrador', hi: 'ड्राफ़्ट' },
    'Closed': { fr: 'Fermé', es: 'Cerrado', hi: 'बंद' },
    'Open': { fr: 'Ouvert', es: 'Abierto', hi: 'खुला' },
    'Acknowledged': { fr: 'Confirmé', es: 'Confirmado', hi: 'स्वीकृत' },
    'Awaiting parent': { fr: 'En attente du parent', es: 'Esperando al padre', hi: 'अभिभावक की प्रतीक्षा' },
    'Awaiting review': { fr: 'En attente de révision', es: 'Esperando revisión', hi: 'समीक्षा की प्रतीक्षा' },
    'Overdue': { fr: 'En retard', es: 'Vencido', hi: 'अतिदेय' },
    'Paid': { fr: 'Payé', es: 'Pagado', hi: 'भुगतान किया' },
    'Unpaid': { fr: 'Impayé', es: 'Sin pagar', hi: 'अवैतनिक' },
    'Sent': { fr: 'Envoyé', es: 'Enviado', hi: 'भेजा गया' },
    'Failed': { fr: 'Échec', es: 'Fallido', hi: 'विफल' },
    'Skipped': { fr: 'Ignoré', es: 'Omitido', hi: 'छोड़ा गया' },
    'Delivered': { fr: 'Livré', es: 'Entregado', hi: 'वितरित' },

    // ── Dashboard / empty states ──
    'Business insights': { fr: 'Analyses d’activité', es: 'Información del negocio', hi: 'व्यावसायिक अंतर्दृष्टि' },
    'Total enrolled': { fr: 'Total inscrits', es: 'Total matriculados', hi: 'कुल नामांकित' },
    'Here right now': { fr: 'Présents en ce moment', es: 'Presentes ahora', hi: 'अभी उपस्थित' },
    'Staff on floor': { fr: 'Personnel sur place', es: 'Personal presente', hi: 'मौजूद स्टाफ़' },
    'Receivables': { fr: 'Créances', es: 'Cuentas por cobrar', hi: 'प्राप्य राशि' },
    'Outstanding': { fr: 'En souffrance', es: 'Pendiente', hi: 'बकाया' },
    'Activity feed': { fr: 'Fil d’activité', es: 'Actividad reciente', hi: 'गतिविधि फ़ीड' },
    'No data yet.': { fr: 'Aucune donnée pour l’instant.', es: 'Aún no hay datos.', hi: 'अभी कोई डेटा नहीं।' },
    'No messages yet.': { fr: 'Aucun message pour l’instant.', es: 'Aún no hay mensajes.', hi: 'अभी कोई संदेश नहीं।' },
    'No broadcasts sent yet.': { fr: 'Aucune diffusion envoyée.', es: 'Aún no se han enviado difusiones.', hi: 'अभी कोई प्रसारण नहीं भेजा गया।' },
    'No payment plans on file.': { fr: 'Aucun plan de paiement au dossier.', es: 'No hay planes de pago registrados.', hi: 'कोई भुगतान योजना दर्ज नहीं है।' },
    'Loading…': { fr: 'Chargement…', es: 'Cargando…', hi: 'लोड हो रहा है…' },
    'Loading your workspace…': { fr: 'Chargement de votre espace…', es: 'Cargando su espacio…', hi: 'आपका कार्यक्षेत्र लोड हो रहा है…' },

    // ── Banner descriptions (kept in step with SCREEN_DESC in app-v2-shell.js) ──
    'Today at a glance: enrolment, attendance, staffing and receivables.': { fr: 'La journée en un coup d’œil : inscriptions, présences, personnel et créances.', es: 'El día de un vistazo: matrícula, asistencia, personal y cobros.', hi: 'आज एक नज़र में: नामांकन, उपस्थिति, स्टाफ़ और प्राप्य राशि।' },
    'Meals, naps, nappies and notes for each child, as the day happens.': { fr: 'Repas, siestes, couches et notes pour chaque enfant, au fil de la journée.', es: 'Comidas, siestas, pañales y notas de cada niño, a lo largo del día.', hi: 'दिन भर हर बच्चे के भोजन, झपकी, डायपर और टिप्पणियाँ।' },
    'Conversations with families and staff.': { fr: 'Échanges avec les familles et le personnel.', es: 'Conversaciones con familias y personal.', hi: 'परिवारों और स्टाफ़ से बातचीत।' },
    'Broadcast news to a centre, a room, or the whole agency.': { fr: 'Diffusez des nouvelles à un centre, une salle ou toute l’agence.', es: 'Envíe noticias a un centro, una sala o toda la agencia.', hi: 'किसी केंद्र, कक्ष या पूरी एजेंसी को समाचार भेजें।' },
    'Staff qualifications and their expiry dates.': { fr: 'Qualifications du personnel et dates d’expiration.', es: 'Cualificaciones del personal y sus fechas de caducidad.', hi: 'स्टाफ़ की योग्यताएँ और उनकी समाप्ति तिथियाँ।' },
    'Hours worked, ready for payroll.': { fr: 'Heures travaillées, prêtes pour la paie.', es: 'Horas trabajadas, listas para la nómina.', hi: 'काम किए गए घंटे, वेतन के लिए तैयार।' },
    'Families waiting for a space.': { fr: 'Familles en attente d’une place.', es: 'Familias en espera de una plaza.', hi: 'स्थान की प्रतीक्षा कर रहे परिवार।' },
    'Injuries, behaviour and safeguarding reports.': { fr: 'Blessures, comportement et signalements de protection.', es: 'Lesiones, conducta e informes de protección.', hi: 'चोटें, व्यवहार और सुरक्षा रिपोर्ट।' },
    'Medication authorised, given and outstanding.': { fr: 'Médicaments autorisés, administrés et en attente.', es: 'Medicación autorizada, administrada y pendiente.', hi: 'अधिकृत, दी गई और शेष दवाइयाँ।' },
    'Every change made in the portal, and by whom.': { fr: 'Chaque modification effectuée dans le portail, et par qui.', es: 'Cada cambio realizado en el portal, y por quién.', hi: 'पोर्टल में किया गया हर परिवर्तन, और किसके द्वारा।' },
    'Staff accounts, roles and access.': { fr: 'Comptes du personnel, rôles et accès.', es: 'Cuentas del personal, roles y acceso.', hi: 'स्टाफ़ खाते, भूमिकाएँ और पहुँच।' },
    'Send a one-off text to staff or families.': { fr: 'Envoyez un SMS ponctuel au personnel ou aux familles.', es: 'Envíe un SMS puntual al personal o a las familias.', hi: 'स्टाफ़ या परिवारों को एक बार का संदेश भेजें।' },
    'Operational issues, tracked to resolution.': { fr: 'Problèmes opérationnels, suivis jusqu’à leur résolution.', es: 'Incidencias operativas, seguidas hasta su resolución.', hi: 'परिचालन समस्याएँ, समाधान तक ट्रैक की गईं।' },
    'Suppliers, purchase orders and bills.': { fr: 'Fournisseurs, bons de commande et factures.', es: 'Proveedores, órdenes de compra y facturas.', hi: 'आपूर्तिकर्ता, क्रय आदेश और बिल।' },
    'Ready-made reports you can print or export.': { fr: 'Rapports prêts à imprimer ou à exporter.', es: 'Informes listos para imprimir o exportar.', hi: 'तैयार रिपोर्ट, जिन्हें प्रिंट या निर्यात किया जा सकता है।' },
    'Licensing obligations and the evidence for them.': { fr: 'Obligations réglementaires et les preuves correspondantes.', es: 'Obligaciones de licencia y sus evidencias.', hi: 'लाइसेंस दायित्व और उनके प्रमाण।' },
    'Split a large balance into instalments.': { fr: 'Étalez un solde important en versements.', es: 'Divida un saldo grande en cuotas.', hi: 'बड़ी राशि को किश्तों में बाँटें।' },
    'Learning stories and developmental observations for each child.': { fr: 'Récits d’apprentissage et observations du développement de chaque enfant.', es: 'Historias de aprendizaje y observaciones del desarrollo de cada niño.', hi: 'हर बच्चे की सीखने की कहानियाँ और विकास संबंधी अवलोकन।' },
    'Guides for every part of KiddieTrac.': { fr: 'Guides pour chaque partie de KiddieTrac.', es: 'Guías para cada parte de KiddieTrac.', hi: 'KiddieTrac के हर हिस्से के लिए गाइड।' },

    // ── Added after checking the rendered DOM (these are the exact strings the app
    //    prints, which differ from what you would guess: the sidebar headers are Title
    //    Case and CSS upper-cases them, the greeting is its own text node ending in a
    //    comma, and so on). ──
    'Log a moment': { fr: 'Consigner un moment', es: 'Registrar un momento', hi: 'एक पल दर्ज करें' },
    'Daily log': { fr: 'Journal quotidien', es: 'Registro diario', hi: 'दैनिक लॉग' },
    'Quick-tap diaper / bathroom / nap / meal / bottle entries. They roll up into the parent’s Today screen instantly.': { fr: 'Saisie rapide : couche, toilettes, sieste, repas, biberon. Tout apparaît aussitôt dans l’écran Aujourd’hui du parent.', es: 'Registro rápido: pañal, baño, siesta, comida, biberón. Aparece al instante en la pantalla Hoy del padre.', hi: 'त्वरित प्रविष्टि: डायपर, बाथरूम, झपकी, भोजन, बोतल। ये तुरंत अभिभावक की आज स्क्रीन पर दिखते हैं।' },
    'Child': { fr: 'Enfant', es: 'Niño', hi: 'बच्चा' },
    'Diaper': { fr: 'Couche', es: 'Pañal', hi: 'डायपर' },
    'Bathroom': { fr: 'Toilettes', es: 'Baño', hi: 'बाथरूम' },
    'Nap': { fr: 'Sieste', es: 'Siesta', hi: 'झपकी' },
    'Meal': { fr: 'Repas', es: 'Comida', hi: 'भोजन' },
    'Snack': { fr: 'Collation', es: 'Merienda', hi: 'नाश्ता' },
    'Bottle': { fr: 'Biberon', es: 'Biberón', hi: 'बोतल' },
    'Sunscreen': { fr: 'Écran solaire', es: 'Protector solar', hi: 'सनस्क्रीन' },
    'Mood': { fr: 'Humeur', es: 'Ánimo', hi: 'मनोदशा' },
    'Other': { fr: 'Autre', es: 'Otro', hi: 'अन्य' },
    "Today's log": { fr: 'Journal du jour', es: 'Registro de hoy', hi: 'आज का लॉग' },
    'Nothing logged today yet.': { fr: 'Rien de consigné aujourd’hui.', es: 'Aún no hay registros de hoy.', hi: 'आज अभी कुछ दर्ज नहीं हुआ।' },
    'Check-in': { fr: 'Arrivée', es: 'Entrada', hi: 'चेक-इन' },
    'Check-out': { fr: 'Départ', es: 'Salida', hi: 'चेक-आउट' },
    'Signed in': { fr: 'Arrivé', es: 'Ingresó', hi: 'साइन इन' },
    'Signed out': { fr: 'Parti', es: 'Salió', hi: 'साइन आउट' },
    // ── Batch 2: swept from every screen ──
    '+ Add family': { fr: '+ Ajouter une famille', es: '+ Añadir familia', hi: '+ परिवार जोड़ें' },
    '+ Add record': { fr: '+ Ajouter un enregistrement', es: '+ Añadir registro', hi: '+ रिकॉर्ड जोड़ें' },
    '+ Add shift': { fr: '+ Ajouter un quart', es: '+ Añadir turno', hi: '+ शिफ़्ट जोड़ें' },
    '+ Add tier': { fr: '+ Ajouter un palier', es: '+ Añadir nivel', hi: '+ स्तर जोड़ें' },
    '+ Create agency': { fr: '+ Créer une agence', es: '+ Crear agencia', hi: '+ एजेंसी बनाएँ' },
    '+ Create slots': { fr: '+ Créer des créneaux', es: '+ Crear franjas', hi: '+ स्लॉट बनाएँ' },
    '+ Generate plan': { fr: '+ Générer un plan', es: '+ Generar plan', hi: '+ योजना बनाएँ' },
    '+ Launch campaign': { fr: '+ Lancer une campagne', es: '+ Lanzar campaña', hi: '+ अभियान शुरू करें' },
    '+ Log late pickup': { fr: '+ Consigner un retard', es: '+ Registrar recogida tardía', hi: '+ देर से पिकअप दर्ज करें' },
    '+ New': { fr: '+ Nouveau', es: '+ Nuevo', hi: '+ नया' },
    '+ New campaign': { fr: '+ Nouvelle campagne', es: '+ Nueva campaña', hi: '+ नया अभियान' },
    '+ New closure': { fr: '+ Nouvelle fermeture', es: '+ Nuevo cierre', hi: '+ नया अवकाश' },
    '+ New code': { fr: '+ Nouveau code', es: '+ Nuevo código', hi: '+ नया कोड' },
    '+ New drip campaign': { fr: '+ Nouvelle campagne automatisée', es: '+ Nueva campaña automatizada', hi: '+ नया ड्रिप अभियान' },
    '+ New form': { fr: '+ Nouveau formulaire', es: '+ Nuevo formulario', hi: '+ नया फ़ॉर्म' },
    '+ New plan': { fr: '+ Nouveau plan', es: '+ Nuevo plan', hi: '+ नई योजना' },
    '+ New workflow': { fr: '+ Nouveau flux', es: '+ Nuevo flujo', hi: '+ नया वर्कफ़्लो' },
    '+ New zone': { fr: '+ Nouvelle zone', es: '+ Nueva zona', hi: '+ नया क्षेत्र' },
    '+ Plan trip': { fr: '+ Planifier une sortie', es: '+ Planificar excursión', hi: '+ यात्रा योजना बनाएँ' },
    '+ Post open shift': { fr: '+ Publier un quart ouvert', es: '+ Publicar turno libre', hi: '+ खुली शिफ़्ट पोस्ट करें' },
    '+ Request time off': { fr: '+ Demander un congé', es: '+ Solicitar tiempo libre', hi: '+ छुट्टी का अनुरोध करें' },
    '+ Schedule increase': { fr: '+ Planifier une hausse', es: '+ Programar aumento', hi: '+ वृद्धि निर्धारित करें' },
    '+ Sign a new document': { fr: '+ Signer un nouveau document', es: '+ Firmar un nuevo documento', hi: '+ नया दस्तावेज़ हस्ताक्षरित करें' },
    '+ Upload template': { fr: '+ Téléverser un modèle', es: '+ Subir plantilla', hi: '+ टेम्पलेट अपलोड करें' },
    '+ Upload photo': { fr: '+ Téléverser une photo', es: '+ Subir foto', hi: '+ फ़ोटो अपलोड करें' },
    '+ Upload video': { fr: '+ Téléverser une vidéo', es: '+ Subir vídeo', hi: '+ वीडियो अपलोड करें' },
    '+ Add': { fr: '+ Ajouter', es: '+ Añadir', hi: '+ जोड़ें' },
    'Menu': { fr: 'Menu', es: 'Menú', hi: 'मेन्यू' },
    'More': { fr: 'Plus', es: 'Más', hi: 'और' },
    'Note': { fr: 'Note', es: 'Nota', hi: 'टिप्पणी' },
    'News': { fr: 'Nouvelles', es: 'Noticias', hi: 'समाचार' },
    'Naps': { fr: 'Siestes', es: 'Siestas', hi: 'झपकियाँ' },
    'Meals': { fr: 'Repas', es: 'Comidas', hi: 'भोजन' },
    'Lunch': { fr: 'Dîner', es: 'Almuerzo', hi: 'दोपहर का भोजन' },
    'Breakfast': { fr: 'Déjeuner', es: 'Desayuno', hi: 'नाश्ता' },
    'Plan': { fr: 'Plan', es: 'Plan', hi: 'योजना' },
    'Play': { fr: 'Jeu', es: 'Juego', hi: 'खेल' },
    'Sick': { fr: 'Malade', es: 'Enfermo', hi: 'बीमार' },
    'Skip': { fr: 'Ignorer', es: 'Omitir', hi: 'छोड़ें' },
    'Step': { fr: 'Étape', es: 'Paso', hi: 'चरण' },
    'Term': { fr: 'Terme', es: 'Término', hi: 'अवधि' },
    'Tier': { fr: 'Palier', es: 'Nivel', hi: 'स्तर' },
    'Time': { fr: 'Heure', es: 'Hora', hi: 'समय' },
    'Trip': { fr: 'Sortie', es: 'Excursión', hi: 'यात्रा' },
    'Type': { fr: 'Type', es: 'Tipo', hi: 'प्रकार' },
    'When': { fr: 'Quand', es: 'Cuándo', hi: 'कब' },
    'Who': { fr: 'Qui', es: 'Quién', hi: 'कौन' },
    'Alert': { fr: 'Alerte', es: 'Alerta', hi: 'अलर्ट' },
    'Apply': { fr: 'Appliquer', es: 'Aplicar', hi: 'लागू करें' },
    'Block': { fr: 'Bloquer', es: 'Bloquear', hi: 'ब्लॉक करें' },
    'Break': { fr: 'Pause', es: 'Descanso', hi: 'विराम' },
    'Count': { fr: 'Nombre', es: 'Recuento', hi: 'गिनती' },
    'Dates': { fr: 'Dates', es: 'Fechas', hi: 'तिथियाँ' },
    'Delay': { fr: 'Délai', es: 'Retraso', hi: 'विलंब' },
    'Error': { fr: 'Erreur', es: 'Error', hi: 'त्रुटि' },
    'Given': { fr: 'Administré', es: 'Administrado', hi: 'दी गई' },
    'Hours': { fr: 'Heures', es: 'Horas', hi: 'घंटे' },
    'Inbox': { fr: 'Boîte de réception', es: 'Bandeja de entrada', hi: 'इनबॉक्स' },
    'Trash': { fr: 'Corbeille', es: 'Papelera', hi: 'ट्रैश' },
    'Label': { fr: 'Étiquette', es: 'Etiqueta', hi: 'लेबल' },
    'Leads': { fr: 'Prospects', es: 'Clientes potenciales', hi: 'संभावित ग्राहक' },
    'Month': { fr: 'Mois', es: 'Mes', hi: 'महीना' },
    'Reply': { fr: 'Répondre', es: 'Responder', hi: 'उत्तर दें' },
    'Rooms': { fr: 'Salles', es: 'Salas', hi: 'कक्ष' },
    'Route': { fr: 'Circuit', es: 'Ruta', hi: 'मार्ग' },
    'Score': { fr: 'Score', es: 'Puntuación', hi: 'स्कोर' },
    'Share': { fr: 'Partager', es: 'Compartir', hi: 'साझा करें' },
    'Start': { fr: 'Début', es: 'Inicio', hi: 'प्रारंभ' },
    'End': { fr: 'Fin', es: 'Fin', hi: 'समाप्त' },
    'Title': { fr: 'Titre', es: 'Título', hi: 'शीर्षक' },
    'Trial': { fr: 'Essai', es: 'Prueba', hi: 'ट्रायल' },
    'Users': { fr: 'Utilisateurs', es: 'Usuarios', hi: 'उपयोगकर्ता' },
    'Value': { fr: 'Valeur', es: 'Valor', hi: 'मूल्य' },
    'Views': { fr: 'Vues', es: 'Vistas', hi: 'दृश्य' },
    'Age': { fr: 'Âge', es: 'Edad', hi: 'आयु' },
    'Fee': { fr: 'Frais', es: 'Tarifa', hi: 'शुल्क' },
    'Dose': { fr: 'Dose', es: 'Dosis', hi: 'खुराक' },
    'Code': { fr: 'Code', es: 'Código', hi: 'कोड' },
    'City': { fr: 'Ville', es: 'Ciudad', hi: 'शहर' },
    'Address': { fr: 'Adresse', es: 'Dirección', hi: 'पता' },
    'Copy': { fr: 'Copier', es: 'Copiar', hi: 'कॉपी करें' },
    'Done': { fr: 'Terminé', es: 'Hecho', hi: 'पूर्ण' },
    'Deny': { fr: 'Refuser', es: 'Denegar', hi: 'अस्वीकार करें' },
    'Mark': { fr: 'Marquer', es: 'Marcar', hi: 'चिह्नित करें' },
    'Book': { fr: 'Réserver', es: 'Reservar', hi: 'बुक करें' },
    'Call': { fr: 'Appeler', es: 'Llamar', hi: 'कॉल करें' },
    'Chat': { fr: 'Discussion', es: 'Chat', hi: 'चैट' },
    'Yes': { fr: 'Oui', es: 'Sí', hi: 'हाँ' },
    'No': { fr: 'Non', es: 'No', hi: 'नहीं' },
    'Low': { fr: 'Faible', es: 'Bajo', hi: 'कम' },
    'High': { fr: 'Élevé', es: 'Alto', hi: 'उच्च' },
    'Male': { fr: 'Masculin', es: 'Masculino', hi: 'पुरुष' },
    'Female': { fr: 'Féminin', es: 'Femenino', hi: 'महिला' },
    'Run': { fr: 'Exécuter', es: 'Ejecutar', hi: 'चलाएँ' },
    'Now': { fr: 'Maintenant', es: 'Ahora', hi: 'अभी' },
    'Free': { fr: 'Gratuit', es: 'Gratis', hi: 'निःशुल्क' },
    'Gaps': { fr: 'Écarts', es: 'Brechas', hi: 'अंतराल' },
    'Item': { fr: 'Article', es: 'Artículo', hi: 'आइटम' },
    'Live': { fr: 'En direct', es: 'En vivo', hi: 'लाइव' },
    'Load': { fr: 'Charger', es: 'Cargar', hi: 'लोड करें' },
    'Archived': { fr: 'Archivé', es: 'Archivado', hi: 'संग्रहीत' },
    'Cancelled': { fr: 'Annulé', es: 'Cancelado', hi: 'रद्द' },
    'Anonymised': { fr: 'Anonymisé', es: 'Anonimizado', hi: 'अनाम' },
    'Autopay': { fr: 'Prélèvement automatique', es: 'Pago automático', hi: 'स्वतः भुगतान' },
    'Bi-weekly': { fr: 'Aux deux semaines', es: 'Quincenal', hi: 'पाक्षिक' },
    'Weekly': { fr: 'Hebdomadaire', es: 'Semanal', hi: 'साप्ताहिक' },
    'Monthly': { fr: 'Mensuel', es: 'Mensual', hi: 'मासिक' },
    'Daily': { fr: 'Quotidien', es: 'Diario', hi: 'दैनिक' },
    'At/over capacity': { fr: 'À/au-delà de la capacité', es: 'En/por encima de la capacidad', hi: 'क्षमता पर/से अधिक' },
    'Booked by': { fr: 'Réservé par', es: 'Reservado por', hi: 'द्वारा बुक' },
    'Billing period': { fr: 'Période de facturation', es: 'Período de facturación', hi: 'बिलिंग अवधि' },
    'Billing status': { fr: 'Statut de facturation', es: 'Estado de facturación', hi: 'बिलिंग स्थिति' },
    'Billing defaults': { fr: 'Paramètres de facturation par défaut', es: 'Valores de facturación predeterminados', hi: 'बिलिंग डिफ़ॉल्ट' },
    'Brand settings': { fr: 'Paramètres de marque', es: 'Ajustes de marca', hi: 'ब्रांड सेटिंग्स' },
    'Accepted payment methods': { fr: 'Modes de paiement acceptés', es: 'Métodos de pago aceptados', hi: 'स्वीकृत भुगतान विधियाँ' },
    'Agency growth': { fr: 'Croissance de l’agence', es: 'Crecimiento de la agencia', hi: 'एजेंसी वृद्धि' },
    'Agency analytics dashboard': { fr: 'Tableau de bord analytique de l’agence', es: 'Panel de analítica de la agencia', hi: 'एजेंसी विश्लेषण डैशबोर्ड' },
    'All accounts in your agency': { fr: 'Tous les comptes de votre agence', es: 'Todas las cuentas de su agencia', hi: 'आपकी एजेंसी के सभी खाते' },
    'All enrolled families': { fr: 'Toutes les familles inscrites', es: 'Todas las familias matriculadas', hi: 'सभी नामांकित परिवार' },
    'All families by risk': { fr: 'Toutes les familles par risque', es: 'Todas las familias por riesgo', hi: 'जोखिम के अनुसार सभी परिवार' },
    'All reports': { fr: 'Tous les rapports', es: 'Todos los informes', hi: 'सभी रिपोर्ट' },
    'All roles': { fr: 'Tous les rôles', es: 'Todos los roles', hi: 'सभी भूमिकाएँ' },
    'All your locations': { fr: 'Tous vos emplacements', es: 'Todas sus ubicaciones', hi: 'आपके सभी स्थान' },
    'Attendance & daily logs': { fr: 'Présences et journaux quotidiens', es: 'Asistencia y registros diarios', hi: 'उपस्थिति और दैनिक लॉग' },
    'Attendance anomalies': { fr: 'Anomalies de présence', es: 'Anomalías de asistencia', hi: 'उपस्थिति विसंगतियाँ' },
    'Attendance log': { fr: 'Journal de présence', es: 'Registro de asistencia', hi: 'उपस्थिति लॉग' },
    'Active drip campaigns': { fr: 'Campagnes automatisées actives', es: 'Campañas automatizadas activas', hi: 'सक्रिय ड्रिप अभियान' },
    'Age band': { fr: 'Tranche d’âge', es: 'Franja de edad', hi: 'आयु वर्ग' },
    'Automatic enforcement': { fr: 'Application automatique', es: 'Aplicación automática', hi: 'स्वचालित प्रवर्तन' },
    'Automatically enforce retention': { fr: 'Appliquer automatiquement la conservation', es: 'Aplicar la retención automáticamente', hi: 'प्रतिधारण स्वतः लागू करें' },
    'Applies the periods above every night.': { fr: 'Applique les périodes ci-dessus chaque nuit.', es: 'Aplica los períodos anteriores cada noche.', hi: 'उपरोक्त अवधियाँ हर रात लागू करता है।' },
    'Automatically charge saved cards when invoices are due.': { fr: 'Débite automatiquement les cartes enregistrées à l’échéance des factures.', es: 'Cobra automáticamente las tarjetas guardadas cuando vencen las facturas.', hi: 'चालान देय होने पर सहेजे गए कार्ड स्वतः चार्ज करें।' },
    'AM snack': { fr: 'Collation du matin', es: 'Merienda de la mañana', hi: 'सुबह का नाश्ता' },
    'PM snack': { fr: 'Collation de l’après-midi', es: 'Merienda de la tarde', hi: 'दोपहर का नाश्ता' },
    'Campaigns': { fr: 'Campagnes', es: 'Campañas', hi: 'अभियान' },
    'Analytics': { fr: 'Analytique', es: 'Analítica', hi: 'विश्लेषण' },
    'Channels': { fr: 'Canaux', es: 'Canales', hi: 'चैनल' },
    'Bucket': { fr: 'Groupe', es: 'Grupo', hi: 'समूह' },
    'Agency': { fr: 'Agence', es: 'Agencia', hi: 'एजेंसी' },
    'Agency / centre': { fr: 'Agence / centre', es: 'Agencia / centro', hi: 'एजेंसी / केंद्र' },
    'Agency (optional)': { fr: 'Agence (facultatif)', es: 'Agencia (opcional)', hi: 'एजेंसी (वैकल्पिक)' },
    'Admin': { fr: 'Admin', es: 'Admin', hi: 'एडमिन' },
    'January': { fr: 'Janvier', es: 'Enero', hi: 'जनवरी' },
    'February': { fr: 'Février', es: 'Febrero', hi: 'फ़रवरी' },
    'March': { fr: 'Mars', es: 'Marzo', hi: 'मार्च' },
    'April': { fr: 'Avril', es: 'Abril', hi: 'अप्रैल' },
    'May': { fr: 'Mai', es: 'Mayo', hi: 'मई' },
    'June': { fr: 'Juin', es: 'Junio', hi: 'जून' },
    'July': { fr: 'Juillet', es: 'Julio', hi: 'जुलाई' },
    'August': { fr: 'Août', es: 'Agosto', hi: 'अगस्त' },
    'September': { fr: 'Septembre', es: 'Septiembre', hi: 'सितंबर' },
    'October': { fr: 'Octobre', es: 'Octubre', hi: 'अक्तूबर' },
    'November': { fr: 'Novembre', es: 'Noviembre', hi: 'नवंबर' },
    'December': { fr: 'Décembre', es: 'Diciembre', hi: 'दिसंबर' },
    'Monday': { fr: 'Lundi', es: 'Lunes', hi: 'सोमवार' },
    'Tuesday': { fr: 'Mardi', es: 'Martes', hi: 'मंगलवार' },
    'Wednesday': { fr: 'Mercredi', es: 'Miércoles', hi: 'बुधवार' },
    'Thursday': { fr: 'Jeudi', es: 'Jueves', hi: 'गुरुवार' },
    'Friday': { fr: 'Vendredi', es: 'Viernes', hi: 'शुक्रवार' },
    'Saturday': { fr: 'Samedi', es: 'Sábado', hi: 'शनिवार' },
    'Sunday': { fr: 'Dimanche', es: 'Domingo', hi: 'रविवार' },
    'Announcement sent': { fr: 'Annonce envoyée', es: 'Anuncio enviado', hi: 'घोषणा भेजी गई' },
    'Campaign email sent': { fr: 'Courriel de campagne envoyé', es: 'Correo de campaña enviado', hi: 'अभियान ईमेल भेजा गया' },
    'Centre deleted': { fr: 'Centre supprimé', es: 'Centro eliminado', hi: 'केंद्र हटाया गया' },
    'Changed email settings': { fr: 'Paramètres de messagerie modifiés', es: 'Ajustes de correo modificados', hi: 'ईमेल सेटिंग्स बदली गईं' },
    'Invite re-sent': { fr: 'Invitation renvoyée', es: 'Invitación reenviada', hi: 'आमंत्रण पुनः भेजा गया' },
    'Lesson plan saved': { fr: 'Plan de leçon enregistré', es: 'Plan de clase guardado', hi: 'पाठ योजना सहेजी गई' },
    'Email sent': { fr: 'Courriel envoyé', es: 'Correo enviado', hi: 'ईमेल भेजा गया' },
    'Daily digest sent': { fr: 'Résumé quotidien envoyé', es: 'Resumen diario enviado', hi: 'दैनिक सारांश भेजा गया' },
    'Failed sign-in': { fr: 'Échec de connexion', es: 'Inicio de sesión fallido', hi: 'साइन-इन विफल' },
    'Clocked in / out': { fr: 'Pointage entrée / sortie', es: 'Fichaje entrada / salida', hi: 'क्लॉक इन / आउट' },
    // ── Batch 3: dashboard widgets, reports catalogue, expenses ──
    'Recent activity': { fr: 'Activité récente', es: 'Actividad reciente', hi: 'हाल की गतिविधि' },
    'Enrolled vs licensed seats': { fr: 'Inscrits vs places autorisées', es: 'Matriculados frente a plazas autorizadas', hi: 'नामांकित बनाम लाइसेंस स्थान' },
    'Enrollment roster': { fr: 'Liste des inscriptions', es: 'Lista de matrícula', hi: 'नामांकन सूची' },
    'Centre capacity': { fr: 'Capacité du centre', es: 'Capacidad del centro', hi: 'केंद्र क्षमता' },
    'Manage centres, users, families, and branding': { fr: 'Gérez les centres, les utilisateurs, les familles et l’image de marque', es: 'Gestione centros, usuarios, familias y marca', hi: 'केंद्र, उपयोगकर्ता, परिवार और ब्रांडिंग प्रबंधित करें' },
    'No staff active right now.': { fr: 'Aucun membre du personnel actif pour l’instant.', es: 'Ningún miembro del personal activo ahora.', hi: 'अभी कोई स्टाफ़ सक्रिय नहीं है।' },
    'Not in': { fr: 'Absent', es: 'Ausente', hi: 'अनुपस्थित' },
    'Compliant': { fr: 'Conforme', es: 'Conforme', hi: 'अनुपालक' },
    'Expired certs': { fr: 'Certificats expirés', es: 'Certificados vencidos', hi: 'समाप्त प्रमाणपत्र' },
    'Staff certifications': { fr: 'Certifications du personnel', es: 'Certificaciones del personal', hi: 'स्टाफ़ प्रमाणपत्र' },
    'MFA not enrolled': { fr: 'MFA non activé', es: 'MFA no activado', hi: 'MFA सक्षम नहीं' },
    'Directors + agency admins missing two-factor': { fr: 'Directeurs et administrateurs sans double authentification', es: 'Directores y administradores sin doble factor', hi: 'दो-कारक रहित निदेशक और एडमिन' },
    'By status': { fr: 'Par statut', es: 'Por estado', hi: 'स्थिति के अनुसार' },
    'Date range applies to dated reports (attendance, payments, invoices, staff hours).': { fr: 'La plage de dates s’applique aux rapports datés (présences, paiements, factures, heures du personnel).', es: 'El rango de fechas se aplica a los informes con fecha (asistencia, pagos, facturas, horas del personal).', hi: 'तिथि सीमा दिनांकित रिपोर्ट पर लागू होती है (उपस्थिति, भुगतान, चालान, स्टाफ़ घंटे)।' },
    'Every child with status, family and centre.': { fr: 'Chaque enfant avec son statut, sa famille et son centre.', es: 'Cada niño con su estado, familia y centro.', hi: 'स्थिति, परिवार और केंद्र सहित हर बच्चा।' },
    'Families with contact and billing details.': { fr: 'Familles avec coordonnées et détails de facturation.', es: 'Familias con datos de contacto y facturación.', hi: 'संपर्क और बिलिंग विवरण सहित परिवार।' },
    'Daily child check-in / check-out for the period.': { fr: 'Arrivées / départs quotidiens des enfants pour la période.', es: 'Entrada / salida diaria de los niños del período.', hi: 'अवधि के लिए दैनिक बच्चा चेक-इन / चेक-आउट।' },
    'Educator clock-in / clock-out hours worked.': { fr: 'Heures travaillées (pointage) des éducateurs.', es: 'Horas trabajadas (fichaje) de los educadores.', hi: 'शिक्षक के काम किए गए क्लॉक-इन / क्लॉक-आउट घंटे।' },
    'Staff hours': { fr: 'Heures du personnel', es: 'Horas del personal', hi: 'स्टाफ़ घंटे' },
    'Invoices issued, amounts paid and owing.': { fr: 'Factures émises, montants payés et dus.', es: 'Facturas emitidas, importes pagados y adeudados.', hi: 'जारी चालान, भुगतान और बकाया राशि।' },
    'Invoices & balances': { fr: 'Factures et soldes', es: 'Facturas y saldos', hi: 'चालान और शेष राशि' },
    'Payments collected in the period.': { fr: 'Paiements perçus durant la période.', es: 'Pagos cobrados en el período.', hi: 'अवधि में एकत्र भुगतान।' },
    'Payments received': { fr: 'Paiements reçus', es: 'Pagos recibidos', hi: 'प्राप्त भुगतान' },
    'Paid this month': { fr: 'Payé ce mois-ci', es: 'Pagado este mes', hi: 'इस महीने भुगतान किया' },
    'Logged incidents / injuries in the period.': { fr: 'Incidents / blessures consignés durant la période.', es: 'Incidentes / lesiones registrados en el período.', hi: 'अवधि में दर्ज घटनाएँ / चोटें।' },
    'Incidents & injuries': { fr: 'Incidents et blessures', es: 'Incidentes y lesiones', hi: 'घटनाएँ और चोटें' },
    'Learning-story observations recorded.': { fr: 'Observations (récits d’apprentissage) enregistrées.', es: 'Observaciones (historias de aprendizaje) registradas.', hi: 'दर्ज सीखने-कहानी अवलोकन।' },
    'Prospective-family tour requests.': { fr: 'Demandes de visite des familles potentielles.', es: 'Solicitudes de visita de familias potenciales.', hi: 'संभावित परिवारों के दौरे के अनुरोध।' },
    'Children waiting for a spot, oldest first.': { fr: 'Enfants en attente d’une place, du plus ancien au plus récent.', es: 'Niños esperando una plaza, del más antiguo al más reciente.', hi: 'स्थान की प्रतीक्षा कर रहे बच्चे, सबसे पुराने पहले।' },
    'Spend by category': { fr: 'Dépenses par catégorie', es: 'Gasto por categoría', hi: 'श्रेणी के अनुसार व्यय' },
    'Open POs': { fr: 'Bons de commande ouverts', es: 'Órdenes de compra abiertas', hi: 'खुले क्रय आदेश' },
    'Draft or ordered': { fr: 'Brouillon ou commandé', es: 'Borrador o pedido', hi: 'ड्राफ़्ट या ऑर्डर किया गया' },
    'No bills yet.': { fr: 'Aucune facture pour l’instant.', es: 'Aún no hay facturas.', hi: 'अभी कोई बिल नहीं।' },
    'No spend recorded.': { fr: 'Aucune dépense enregistrée.', es: 'No hay gastos registrados.', hi: 'कोई व्यय दर्ज नहीं।' },
    'Priority': { fr: 'Priorité', es: 'Prioridad', hi: 'प्राथमिकता' },
    'Subject': { fr: 'Sujet', es: 'Asunto', hi: 'विषय' },
    'Raised by': { fr: 'Ouvert par', es: 'Abierto por', hi: 'द्वारा उठाया गया' },
    'Roles': { fr: 'Rôles', es: 'Roles', hi: 'भूमिकाएँ' },
    'Resend welcome': { fr: 'Renvoyer le message de bienvenue', es: 'Reenviar la bienvenida', hi: 'स्वागत पुनः भेजें' },
    'Platform': { fr: 'Plateforme', es: 'Plataforma', hi: 'प्लेटफ़ॉर्म' },
    'Overview': { fr: 'Aperçu', es: 'Resumen', hi: 'अवलोकन' },
    'Operations': { fr: 'Opérations', es: 'Operaciones', hi: 'संचालन' },
    'Engagement': { fr: 'Engagement', es: 'Participación', hi: 'सहभागिता' },
    'Administration': { fr: 'Administration', es: 'Administración', hi: 'प्रशासन' },
    'Growth': { fr: 'Croissance', es: 'Crecimiento', hi: 'विकास' },
    'Billing': { fr: 'Facturation', es: 'Facturación', hi: 'बिलिंग' },
    'Platform admin': { fr: 'Admin de la plateforme', es: 'Admin de la plataforma', hi: 'प्लेटफ़ॉर्म एडमिन' },
    'Platform admin (overview)': { fr: 'Admin de la plateforme (aperçu)', es: 'Admin de la plataforma (resumen)', hi: 'प्लेटफ़ॉर्म एडमिन (अवलोकन)' },
    'No medications recorded yet.': { fr: 'Aucun médicament enregistré.', es: 'Aún no hay medicamentos registrados.', hi: 'अभी कोई दवा दर्ज नहीं है।' },
    'Standing orders for children in your centre. Parent authorization required before any dose can be logged.': { fr: 'Ordonnances permanentes pour les enfants de votre centre. L’autorisation du parent est requise avant d’enregistrer une dose.', es: 'Órdenes permanentes para los niños de su centro. Se requiere autorización del padre antes de registrar una dosis.', hi: 'आपके केंद्र के बच्चों के स्थायी आदेश। कोई भी खुराक दर्ज करने से पहले अभिभावक की अनुमति आवश्यक है।' },
    'No children enrolled': { fr: 'Aucun enfant inscrit', es: 'Ningún niño matriculado', hi: 'कोई बच्चा नामांकित नहीं' },
    'No records found': { fr: 'Aucun enregistrement trouvé', es: 'No se encontraron registros', hi: 'कोई रिकॉर्ड नहीं मिला' },
    'No results': { fr: 'Aucun résultat', es: 'Sin resultados', hi: 'कोई परिणाम नहीं' },
    'Nothing to show yet.': { fr: 'Rien à afficher pour l’instant.', es: 'Nada que mostrar todavía.', hi: 'अभी दिखाने के लिए कुछ नहीं है।' },
    'Something went wrong': { fr: 'Une erreur est survenue', es: 'Algo salió mal', hi: 'कुछ गड़बड़ हो गई' },
    'Please refresh the page.': { fr: 'Veuillez actualiser la page.', es: 'Actualice la página.', hi: 'कृपया पृष्ठ रिफ़्रेश करें।' },
    'Shared lesson plans across your agency. Click "Use" to copy a template into your centre.': { fr: 'Plans de leçon partagés dans votre agence. Cliquez sur « Utiliser » pour copier un modèle dans votre centre.', es: 'Planes de clase compartidos en su agencia. Haga clic en «Usar» para copiar una plantilla en su centro.', hi: 'आपकी एजेंसी में साझा पाठ योजनाएँ। किसी टेम्पलेट को अपने केंद्र में कॉपी करने के लिए "उपयोग करें" पर क्लिक करें।' },
    'Choose a family to see their payment plans.': { fr: 'Choisissez une famille pour voir ses plans de paiement.', es: 'Elija una familia para ver sus planes de pago.', hi: 'भुगतान योजनाएँ देखने के लिए परिवार चुनें।' },
    'Select a family…': { fr: 'Sélectionner une famille…', es: 'Seleccione una familia…', hi: 'एक परिवार चुनें…' },
    'Select a family above to view their payment plans.': { fr: 'Sélectionnez une famille ci-dessus pour voir ses plans de paiement.', es: 'Seleccione una familia arriba para ver sus planes de pago.', hi: 'भुगतान योजनाएँ देखने के लिए ऊपर एक परिवार चुनें।' },
    'Only recipients who opted in to SMS and have a phone number on file will receive it.': { fr: 'Seuls les destinataires ayant accepté les SMS et dont le numéro est au dossier le recevront.', es: 'Solo lo recibirán los destinatarios que aceptaron los SMS y tienen un teléfono registrado.', hi: 'केवल वे प्राप्तकर्ता जिन्होंने एसएमएस के लिए सहमति दी है और जिनका फ़ोन नंबर दर्ज है, इसे प्राप्त करेंगे।' },
    'Sort': { fr: 'Trier', es: 'Ordenar', hi: 'क्रमबद्ध करें' },
    'Filter': { fr: 'Filtrer', es: 'Filtrar', hi: 'फ़िल्टर' },
    'Today': { fr: 'Aujourd’hui', es: 'Hoy', hi: 'आज' },
    'Yesterday': { fr: 'Hier', es: 'Ayer', hi: 'कल' },
    'This week': { fr: 'Cette semaine', es: 'Esta semana', hi: 'इस सप्ताह' },
    'Total': { fr: 'Total', es: 'Total', hi: 'कुल' }
  };

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

  function translate(text, loc) {
    var raw = String(text);
    var trimmed = raw.trim();
    if (!trimmed) return null;

    var m = LEAD.exec(trimmed);
    var prefix = m ? m[1] : '';
    var words = m ? m[2].trim() : trimmed;

    // Trailing punctuation is common where a string is glued to a value in the markup —
    // the greeting is its own text node reading "Good evening, ".
    var punct = '';
    var pm = /^(.*?)([,:;]\s*)$/.exec(words);
    if (pm) { words = pm[1]; punct = pm[2]; }

    // Which key matched matters: if the WHOLE string (prefix included) is the key —
    // "+ New medication" — then its translation already carries the "+", and re-attaching
    // the prefix would print "+ + Nouveau médicament".
    var hit = lookup(words);
    var keepPrefix = true;
    if (!hit) { hit = lookup(trimmed); keepPrefix = false; }
    if (!hit) return null;
    var out = hit[loc];
    if (!out) return null;

    var lead = raw.match(/^\s*/)[0];
    var tail = raw.match(/\s*$/)[0];
    return lead + (keepPrefix && prefix ? prefix + out : out) + punct + tail;
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
      var out = translate(hits[i].nodeValue, loc);
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
        var t = translate(v, loc);
        if (t !== null && t !== v) el.setAttribute(a, t);
      });
    }
  }

  function apply() {
    var loc = locale();
    if (loc === 'en') return;               // nothing to do
    try {
      walk(document.getElementById('appMain'), loc);
      walk(document.getElementById('appSidebar'), loc);
      walk(document.getElementById('kt-topbar'), loc);
      walk(document.querySelector('.kt-mobilenav'), loc);
      walk(document.querySelector('#appNav'), loc);
    } catch (e) {}
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

  ['toLocaleDateString', 'toLocaleTimeString', 'toLocaleString'].forEach(function (fn) {
    var orig = Date.prototype[fn];
    Date.prototype[fn] = function (loc, opts) { return orig.call(this, pick(loc), opts); };
  });

  if (window.Intl && Intl.DateTimeFormat) {
    var OrigDTF = Intl.DateTimeFormat;
    var Patched = function (loc, opts) {
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
    t: function (s) { var out = translate(s, locale()); return out === null ? s : out; },
    dict: DICT
  };

  // Screens render asynchronously, so sweep on a short poll as well as on navigation.
  // Idempotent by construction (the WeakSet), so a sweep with nothing new to do makes no
  // DOM changes at all — important, because screens that re-render on mutation would
  // otherwise fight us.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
  else apply();
  window.addEventListener('hashchange', function () { setTimeout(apply, 60); });
  setInterval(apply, 400);
})(window);
