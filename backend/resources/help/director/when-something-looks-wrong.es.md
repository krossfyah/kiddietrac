---
title: Cuando algo parece estar mal
category: Troubleshooting
order: 1
---

# Cuando algo parece estar mal

Una guía rápida para los momentos de «qué raro» más comunes.

## «El personal en piso es 0 pero mis educadores están aquí»

Sus educadores no han registrado su entrada. Necesitan:

1. Abrir Kiddietrac en la tableta
2. Desde la vista de la sala, tocar su nombre o el botón **Fichar entrada**

También puede registrar su entrada desde su cuenta de director en su nombre: vaya a **Personal → [nombre del educador] → Fichar entrada**.

## «Una sala muestra BREACH pero mis números parecen correctos»

Compruebe quién ha fichado realmente. Mire **Personal → Horario** de hoy. Si ve BREACH:

- Puede que la proporción objetivo sea demasiado estricta (puede ajustar la proporción de la sala si no coincide con los estándares de Ontario)
- Puede que un educador esté asignado a otra sala mediante turnos
- Puede que haya niños registrados en una sala donde no está su educador

## «Añadí un niño pero no aparece en la sala»

Varias cosas que revisar:

- ¿El **estado de inscripción** del niño es «enrolled»? (No «waitlist» ni «withdrawn»)
- ¿La **fecha de inicio** es hoy o anterior? (Las fechas de inicio futuras no aparecen hasta ese día)
- ¿La **sala** es la correcta? Mire el perfil del niño.

## «Un padre dice que no ve a su hijo»

Compruebe que:

- El padre está vinculado a la familia correcta
- El niño está inscrito en esa misma familia
- El estado de la cuenta del padre es **active** (no **invited**: debe haber iniciado sesión al menos una vez)

Puede verificarlo en **Familias → [nombre de la familia]**: el niño debe aparecer en la familia, y el padre en la lista de tutores.

## «Generé facturas pero una familia no recibió la suya»

O bien:

- No tiene niños inscritos con cuotas mensuales definidas: compruebe que la inscripción tenga un monthly_fee
- Ya existe una factura para este mes: revise **Familias → Facturas** filtrado por familia
- El correo electrónico no salió: revise su configuración SMTP y el correo de la familia

## «El resumen de IA muestra “Loading...” desde hace horas»

El resumen solo se genera después de las 4 PM. Antes de las 4 PM, los padres verán un mensaje que dice «El resumen diario estará disponible después de las 4 PM». Es intencional: queremos que haya suficiente información del día antes de generarlo.

Si ya pasaron las 4 PM y sigue cargando, puede que la API de Anthropic tenga una interrupción. Consulte `https://status.anthropic.com`. El sistema recurre a un resumen basado en plantilla si no se puede acceder a la API.

## «Una foto que subí no aparece»

- Espere 30 segundos: las fotos subidas tardan unos instantes en procesarse
- Actualice por completo la app del padre: **Ctrl+Mayús+R** en escritorio
- Compruebe que la foto se etiquetó con el niño o los niños correctos al subirla
- Puede que el archivo sea demasiado grande (>8 MB) o de un formato no admitido

## «Un educador no puede iniciar sesión»

Puede que su cuenta esté en estado **invited**, es decir, se le envió una invitación pero no ha completado el primer inicio de sesión. Pídale que:

1. Busque el correo de bienvenida original
2. Use la contraseña temporal de ese correo
3. Inicie sesión una vez con éxito: esto activa su cuenta
4. Cambie su contraseña de inmediato

Si perdió el correo de bienvenida, puede restablecer su contraseña (consulte **Restablecer la contraseña de un usuario**).

## En caso de duda

Haga clic en **Preguntar** en la parte superior de cualquier página de ayuda y describa lo que ocurre. El asistente de IA tiene acceso a toda esta documentación y puede darle una respuesta más específica.

Si la IA no lo sabe, escriba a support@kiddietrac.com con:

- Qué estaba haciendo
- Qué esperaba que ocurriera
- Qué ocurrió realmente
- Una captura de pantalla, si es posible
