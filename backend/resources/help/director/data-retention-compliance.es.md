---
title: Retención de datos y cumplimiento
---
# Retención de datos y cumplimiento

**Settings › Data retention & compliance** es donde un administrador de agencia anota cuánto tiempo se guarda cada tipo
de registro, decide si KiddieTrac borra automáticamente los registros antiguos y configura el aviso de privacidad que
deben leer los padres. Juntos forman la política de retención de su agencia, lo que necesita un auditor o un padre que
pregunta «¿cuánto tiempo guardan esto?». Las normas de cuidado infantil suelen fijar mínimos (por ejemplo, la CCEYA de
Ontario): revise las suyas antes de cambiar un período.

[[open: #data-retention | Abrir Retención de datos y cumplimiento]]

## Fijar los períodos de retención

1. Abra **Settings › Data retention & compliance**. Primero se abre la pestaña **🗓 Retention periods**.
2. En cada línea, escriba cuánto tiempo guardar, en los años o meses indicados: **Child & enrolment records (after departure)**, **Uploaded documents**, **Attendance & daily logs**, **Parent–educator messages (chat)**, **Announcements & news**, **Suspended families**, **Call recordings & transcripts**, **Walk & field trip GPS trails**, **Log of texts sent**, **Log of emails sent** y **Security & audit trail**.
3. Lea la etiqueta de cada línea: **Enforced** significa que la limpieza nocturna actúa sobre ella (cuando la aplicación automática está activa); **Policy only** significa que queda como política y nada se borra automáticamente.
4. Pulse **Save changes**. Aparece un aviso verde **Saved**.

> 💡 En las líneas que dicen «0 = keep», 0 significa guardar para siempre. En una grabación de llamada solo se quitan el audio y la transcripción; en una salida, solo los puntos de ubicación: el registro de la llamada y de la salida se conserva.

## Activar la limpieza automática

1. Abra la pestaña **⚙️ Automatic enforcement**.
2. Elija **When enforcing, records are**: **Anonymised** (se borra el contenido, la fila y las estadísticas quedan) o **Permanently deleted** (se elimina; no se puede deshacer).
3. Active **Automatically enforce retention**.
4. Pulse **Save changes**.

Desde esa noche, KiddieTrac borra solo las líneas marcadas **Enforced**: mensajes de chat, anuncios, familias
suspendidas, grabaciones de llamadas, rutas GPS y los registros de textos y correos, cuando superan su período.

> ⚠️ El borrado es permanente. Acuerde los períodos con su equipo y revíselos dos veces antes de activarlo.

## Pedir a los padres que lean su aviso de privacidad

1. Abra la pestaña **🔒 Privacy & consent**.
2. Active **Ask parents to read our privacy notice**. Cada padre ve el aviso una vez en el portal y toca «Lo he leído»; se registra la fecha.
3. Escriba su **Privacy policy URL** y el **Data-protection contact email** adonde los padres envían solicitudes de acceso o borrado.
4. Pulse **Save changes**. Debajo del interruptor ve quién ha leído y quién no el aviso actual.
5. Si cambió el texto del aviso pero mantuvo el mismo enlace, pulse **Ask parents again** y confirme con **Ask again**. Todos los padres lo verán otra vez al abrir el portal.

> ℹ️ Cambiar el enlace vuelve a pedirlo a todos automáticamente. Apagar el interruptor queda en el registro de auditoría.

Use la pestaña **📝 Notes** para notas internas sobre sus obligaciones; solo sus administradores las ven.

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador de agencia | Cambiar todos los ajustes de la agencia activa. |
| Director de centro, educador | No disponible. |
| Administrador de plataforma | La agencia elegida en el selector de agencias. |

## Preguntas frecuentes y problemas

??? No se borró nada después de guardar un período más corto. | O **Automatically enforce retention** está apagado, o la línea es **Policy only**. Esos registros nunca se borran automáticamente.
??? ¿Puedo recuperar registros borrados de forma permanente? | No. Elija **Anonymised** si podría necesitar las filas más adelante.
??? «Could not save» | Revise que los números sean enteros y no negativos, e intente de nuevo. Si sigue pasando, abra un ticket desde Ayuda.
??? ¿Los padres reciben un correo cuando vuelvo a pedirlo? | No. Ven el aviso la próxima vez que abren el portal.

## Pantallas relacionadas

[[open: #privacy-requests | Solicitudes de privacidad]] [[open: #privacy-breaches | Violaciones de privacidad]] [[open: #audit-logs | Registro de auditoría]]

Vea también [[privacy-requests]] y [[privacy-breaches]].
