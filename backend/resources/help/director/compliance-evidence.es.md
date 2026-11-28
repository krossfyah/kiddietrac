---
title: Evidencia de cumplimiento (preparación SOC 2)
---
# Evidencia de cumplimiento (preparación SOC 2)

**Platform › Compliance evidence** reúne lo que un auditor pide primero cuando KiddieTrac se prepara para un informe SOC 2: las políticas escritas y la matriz de controles, y evidencia en vivo de que los controles existen y funcionan (quién tiene acceso de administrador, eventos de seguridad, copias de seguridad, tareas programadas y cambios de código). Aquí también se firma la revisión trimestral de accesos. El informe SOC 2 en sí lo emite una firma de contadores públicos independiente.

[[open: #compliance-evidence | Abrir Compliance evidence]]

## Leer los mosaicos de controles

1. Abra **Platform › 🛡️ Compliance evidence**.
2. Lea los mosaicos de arriba. Verde está en orden, ámbar necesita atención, rojo es una brecha:
   - **Admins with two-factor** (y cuántos usan también una llave de acceso): debería ser 100 %.
   - **All users with two-factor**.
   - **Admins never signed in**: accesos sin uso que hay que quitar en la revisión.
   - **Sign-ins, last 30 days**: inicios de sesión correctos y fallidos, y fallas de doble factor.
   - **Latest backup** y cuántas copias se guardan.
   - **TLS certificate (app / api)**: días restantes; los certificados se renuevan solos.
   - **Audit log**: cuántos eventos se guardan y desde cuándo.
   - **Access review**: la fecha de la última firma, o **Due now (quarterly)**.

## Descargar evidencia para un auditor

1. Vaya a **Evidence exports**.
2. Pulse la exportación que necesita: **👥 Access review (CSV)**, **🔐 Security events, 90 days (CSV)**, **🗄️ Backups (CSV)**, **⏱️ Scheduled jobs** o **🧩 Code changes, 90 days**.
3. El archivo se descarga. Se genera con los datos en vivo de ese momento.

> ℹ️ Cada descarga queda registrada en el registro de auditoría, con quién descargó qué y cuándo.

## Descargar las políticas

1. Vaya a **Policies and control matrix**.
2. Empiece por el README, la matriz de controles y el plan de corrección.
3. Pulse **Download** junto a cada documento que necesite. La columna **Updated** muestra la fecha de cada uno.

## Firmar la revisión trimestral de accesos

Hágalo cada trimestre. La firma guarda una instantánea de cada cuenta de administrador tal como estaba ese día.

1. En **Evidence exports**, pulse **👥 Access review (CSV)** y abra el archivo.
2. Revise cada cuenta de administrador: ¿sigue siendo necesaria y tiene doble factor?
3. Corrija lo que no esté bien (quite accesos sin uso, pida que activen el doble factor).
4. En **Quarterly access review**, escriba **What did you find?** (por ejemplo cuántas cuentas revisó y qué estaba mal).
5. Escriba **What did you change?**
6. Marque **I reviewed every admin-level account listed in the access review.**
7. Pulse **✍️ Sign off access review**. Aparece el aviso **Access review signed off** y la revisión se agrega a la lista sobre el formulario (**Date**, **Reviewer**, **Accounts**, **Findings**, **Changes**).

[[show: #compliance-evidence @ Sign off access review | Muéstreme]]

## Quién puede hacer qué

| Rol | Acceso |
|---|---|
| Administrador de la plataforma | Todo en esta pantalla. |
| Administradores de agencia, directores, auditores | Sin acceso: es la evidencia propia de KiddieTrac. El cumplimiento de una agencia está en sus pantallas de Cumplimiento y Retención de datos. |

## Preguntas frecuentes y problemas

??? "Write down what you found first." | La casilla What did you find? está vacía. Describa lo que encontró la revisión antes de firmar.
??? "Tick the box to confirm you reviewed every account." | Marque la casilla de confirmación sobre el botón de firma.
??? "Download failed (…)". | No se pudo generar la exportación. Actualice e inténtelo de nuevo; solo los administradores de la plataforma pueden descargar.
??? "No access review has been recorded yet." | Nadie ha firmado una todavía. Haga la primera revisión ahora.
??? El mosaico Access review está en rojo. | La última revisión tiene más de un trimestre, o no hay ninguna. Haga una revisión y fírmela.
??? ¿Firmar envía un correo a alguien? | No. Se guarda aquí y se conserva como evidencia.

## Pantallas relacionadas

[[open: #audit-logs | Registro de auditoría]] [[open: #backups | Copias de seguridad]] [[open: #security-alerts | Alertas de seguridad]] [[open: #server-health | Estado del servidor]] [[open: #data-retention | Retención de datos y cumplimiento]]
