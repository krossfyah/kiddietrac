---
title: Registro de auditoría
---
# Registro de auditoría

El registro de auditoría guarda quién hizo qué, y cuándo, en su agencia: inicios de sesión, cambios en niños, familias,
personal, facturas y ajustes, correos, mensajes de texto, llamadas y chat. Úselo para saber «¿quién cambió esto?»,
revisar lo que se envió a una familia o entregar pruebas a un inspector o auditor. Nada aquí se puede editar ni borrar.

[[open: #audit-logs | Abrir el registro de auditoría]]

## Encontrar una acción

1. Abra **Administration › Audit log**. Primero se abre la pestaña **📜 Audit log**, con la entrada más reciente arriba.
2. Elija una **Action** (por ejemplo, un inicio de sesión o un cambio en una factura) y, si hace falta, una **Entity** (el tipo de registro: usuario, centro, factura…). **All** muestra todo.
3. Escriba un nombre, correo o palabra en **Search**: busca en la acción y en sus detalles.
4. Use **From** y **To** para limitar las fechas.
5. Haga clic en una fila para ver el detalle completo: quién lo hizo, desde qué dirección y qué cambió exactamente.
6. Pulse **Reset** para borrar todos los filtros.

En un teléfono los filtros se pliegan detrás de **⚙️ Filters** (indica cuántos hay activos) y cada entrada es una tarjeta.

> ℹ️ Todas las horas de esta pantalla están en la zona horaria de su agencia. Incluyen milisegundos, así que las entradas del mismo segundo se muestran en el orden real.

## Exportar para un inspector o auditor

1. Ponga los filtros que necesite (fechas, acción, búsqueda).
2. Pulse **⤓ CSV** junto a **Reset**.
3. Abra el archivo en una hoja de cálculo. Tiene hasta 5000 filas, con los mismos filtros y el mismo orden que la pantalla.

## Leer conversaciones de chat

1. Abra la pestaña **💬 Chat conversations** (solo administradores de agencia).
2. Limite con **Search**, **Active from** y **Active until**.
3. Pulse **View transcript** en una conversación. Los mensajes borrados aparecen tachados con la hora en que se borraron; los editados aparecen marcados.
4. Pulse **⬇️ Download transcript** para guardarla como archivo de texto.

> ⚠️ Abrir una transcripción queda registrado en el propio registro, con su nombre y la conversación que abrió.

### Atender un mensaje denunciado

Cuando alguien denuncia un mensaje, aparece un cuadro **🚩 Reported messages** en la pestaña de chat y los administradores reciben un aviso en la campana.

1. Lea la denuncia: quién denunció a quién, el motivo y su nota.
2. Pulse **Open conversation** para leerla en contexto.
3. Resuelva la situación (hable con la persona, quite un acceso) y pulse **Mark handled**.

## Revisar textos, llamadas, correos y pagos

- **📱 Text messages**: cada mensaje de texto enviado o recibido, con su estado y cualquier error. Filtre con **Search**, **From**, **To** y guarde con **⬇️ Export CSV**.
- **📞 Voice calls**: cada llamada automática (anuncios, controles de Safe Arrival) con su duración y el resultado, incluida la tecla que pulsó la persona.
- **📧 Email log** y **⚠️ Email errors**: cada correo que envió la agencia y los que rebotaron o fallaron, con el motivo en palabras sencillas.
- **💳 Payment integrations**: lo que el proveedor de pagos envió y recibió.

[[show: #audit-logs @ 📧 Email log | Muéstreme el registro de correos]]

## Quién puede hacer qué

| Rol | Ve |
|---|---|
| Administrador de agencia | Todas las pestañas de toda la agencia, incluidos chat y correo. |
| Director de centro | Las pestañas de auditoría, pagos, textos y llamadas, solo de su(s) centro(s). Sin chat ni correo. |
| Auditor | El registro de auditoría de la agencia en solo lectura, para revisarlo y exportarlo. |
| Administrador de plataforma | Todo, más **🌐 Platform-level** para eventos que no pertenecen a ninguna agencia (por ejemplo, intentos de inicio de sesión en cuentas que no existen). |

## Preguntas frecuentes y problemas

??? No veo la pestaña Chat conversations o Email log. | Esas pestañas son para administradores de agencia. Los directores de centro y los auditores no las tienen.
??? La lista está vacía. | Revise los filtros: un rango de fechas o una acción de la última vez oculta filas. Pulse **Reset**.
??? Las entradas antiguas muestran .000 después de los segundos. | Las entradas anteriores a septiembre de 2026 se guardaron sin milisegundos. Su orden dentro de ese segundo nunca se registró.
??? ¿Puedo borrar o corregir una entrada? | No. El registro es permanente a propósito, para que sirva como prueba confiable.
??? ¿Ver el registro avisa a alguien? | No, salvo que abrir una transcripción de chat queda anotado en el propio registro.

## Pantallas relacionadas

[[open: #compliance | Panel de cumplimiento]] [[open: #privacy-breaches | Violaciones de privacidad]]

Vea también [[compliance-dashboard]] y [[data-retention-compliance]].
